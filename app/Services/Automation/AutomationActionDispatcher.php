<?php

namespace App\Services\Automation;

use App\Jobs\SendEmailJob;
use App\Jobs\SendWebhookJob;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Redis;

class AutomationActionDispatcher
{
    /**
     * Webhook 缓冲区 Redis Key 前缀。
     */
    private const WEBHOOK_BUFFER_PREFIX = 'automation:webhook:buffer:';

    /**
     * 合并窗口：30 秒内同一 webhookUrl 的 payload 合并为一次发送。
     */
    private const WEBHOOK_MERGE_DELAY_SECONDS = 30;

    /**
     * 判断是否为通用动作。
     */
    public function supports(string $type): bool
    {
        return in_array($type, ['telegram_admin', 'email', 'webhook'], true);
    }

    /**
     * 分发通用动作（telegram/email/webhook）。
     */
    public function dispatch(array $action, array $context, array $meta): array
    {
        $type = (string) ($action['type'] ?? '');

        return match ($type) {
            'telegram_admin' => $this->dispatchTelegramAction($action, $context, $meta),
            'email'          => $this->dispatchEmailAction($action, $context, $meta),
            'webhook'        => $this->dispatchWebhookAction($action, $context, $meta),
            default          => [
                'type'    => $type,
                'ok'      => false,
                'message' => 'unsupported action type',
            ],
        };
    }

    /**
     * Telegram 通知动作（同步）。
     */
    private function dispatchTelegramAction(array $action, array $context, array $meta): array
    {
        $message = $this->buildMessage($action, $context, $meta);
        (new TelegramService())->sendMessageWithAdmin($message);

        return [
            'type' => 'telegram_admin',
            'ok'   => true,
        ];
    }

    /**
     * 邮件通知动作（异步，通过 SendEmailJob 队列）。
     */
    private function dispatchEmailAction(array $action, array $context, array $meta): array
    {
        $message = $this->buildMessage($action, $context, $meta);
        $subjectTemplate = $this->isRecovery($meta)
            ? (string) ($action['recoverSubject'] ?? '[' . ($meta['moduleLabel'] ?? 'Automation') . '] Recovered - {rule_name}')
            : (string) ($action['subject'] ?? '[' . ($meta['moduleLabel'] ?? 'Automation') . '] Alert - {rule_name}');
        $subject = $this->renderTemplate($subjectTemplate, $context);

        $receivers = array_values(array_filter((array) ($action['recipients'] ?? [])));
        $toAdmin = !array_key_exists('toAdmin', $action) || (int) $action['toAdmin'] === 1;
        if ($toAdmin) {
            $adminEmails = User::query()->where('is_admin', 1)->whereNotNull('email')->pluck('email')->all();
            $receivers = array_values(array_unique(array_merge($receivers, $adminEmails)));
        }

        foreach ($receivers as $email) {
            SendEmailJob::dispatch([
                'email'          => $email,
                'subject'        => $subject,
                'template_name'  => 'notify',
                'template_value' => [
                    'name'    => admin_setting('app_name', 'NxPanel'),
                    'content' => $message,
                    'url'     => admin_setting('app_url'),
                ],
            ]);
        }

        return [
            'type'           => 'email',
            'ok'             => true,
            'receiver_count' => count($receivers),
        ];
    }

    /**
     * Webhook 通知动作（异步，通过 SendWebhookJob + Redis 缓冲合并）。
     *
     * 设计：
     *   1. 将当前 payload RPUSH 到 Redis 缓冲区（Key 按 webhookUrl hash 区分）。
     *   2. 用 SET NX + EX 做"只投递一次 Job"的守卫：
     *      - 若 Key 不存在（第一条进来）→ 设置守卫 Key，dispatch SendWebhookJob（delay 30s）。
     *      - 若 Key 已存在（30s 窗口内的后续条）→ 只追加缓冲区，不重复投递 Job。
     *   3. SendWebhookJob 执行时，LRANGE + DEL 原子取出全部 payload，合并后一次性发送。
     */
    private function dispatchWebhookAction(array $action, array $context, array $meta): array
    {
        $webhookUrl = trim((string) ($action['webhookUrl'] ?? ''));
        if ($webhookUrl === '') {
            return [
                'type'    => 'webhook',
                'ok'      => false,
                'message' => 'webhookUrl is required',
            ];
        }

        $message = $this->buildMessage($action, $context, $meta);
        $payload = [
            'event'      => (string) ($meta['event'] ?? 'triggered'),
            'module'     => (string) ($meta['module'] ?? ''),
            'ruleId'     => (int) ($meta['ruleId'] ?? 0),
            'ruleName'   => (string) ($meta['ruleName'] ?? ''),
            'targetType' => (string) ($meta['targetType'] ?? ''),
            'targetId'   => (string) ($meta['targetId'] ?? ''),
            'targetName' => (string) ($meta['targetName'] ?? ''),
            'message'    => $message,
            'executedAt' => now()->toDateTimeString(),
        ];

        // Redis Key：缓冲区列表 + 守卫 Key（用于 NX 去重投递）。
        $urlHash   = substr(md5($webhookUrl), 0, 16);
        $bufferKey = self::WEBHOOK_BUFFER_PREFIX . $urlHash;
        $guardKey  = self::WEBHOOK_BUFFER_PREFIX . $urlHash . ':guard';

        // 将 payload 追加到缓冲区（TTL 设为合并窗口的 2 倍，防止孤儿 Key）。
        $ttl = self::WEBHOOK_MERGE_DELAY_SECONDS * 2;
        Redis::rpush($bufferKey, json_encode($payload));
        Redis::expire($bufferKey, $ttl);

        // NX：同一窗口内只投递一次 Job。
        $isFirstInWindow = Redis::set($guardKey, '1', 'EX', self::WEBHOOK_MERGE_DELAY_SECONDS, 'NX');
        if ($isFirstInWindow) {
            // 动作配置（headers/signing/timeout）随 Job 一起存储，供发送时使用。
            $actionConfig = array_intersect_key($action, array_flip(['headers', 'signing', 'timeoutSeconds']));

            SendWebhookJob::dispatch($webhookUrl, $bufferKey, $actionConfig)
                ->delay(now()->addSeconds(self::WEBHOOK_MERGE_DELAY_SECONDS));
        }

        return [
            'type'      => 'webhook',
            'ok'        => true,
            'queued'    => true,
            'merged'    => !$isFirstInWindow,
            'bufferKey' => $bufferKey,
        ];
    }

    /**
     * 构建动作通知消息（模板渲染）。
     */
    private function buildMessage(array $action, array $context, array $meta): string
    {
        $defaultAlertTemplate   = (string) ($meta['defaultAlertTemplate'] ?? '[Automation Alert] {rule_name} | {target_name}');
        $defaultRecoverTemplate = (string) ($meta['defaultRecoverTemplate'] ?? '[Automation Recovery] {rule_name} | {target_name}');
        $template = $this->isRecovery($meta)
            ? (string) ($action['recoverTemplate'] ?? $defaultRecoverTemplate)
            : (string) ($action['template'] ?? $defaultAlertTemplate);

        return $this->renderTemplate($template, $context);
    }

    /**
     * 判断是否恢复场景。
     */
    private function isRecovery(array $meta): bool
    {
        return (string) ($meta['event'] ?? 'triggered') === 'recovered';
    }

    /**
     * 模板渲染，替换 {placeholder}。
     */
    private function renderTemplate(string $template, array $context): string
    {
        return (string) preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($matches) use ($context) {
            $key   = $matches[1] ?? '';
            $value = $context[$key] ?? '';
            return is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }, $template);
    }
}
