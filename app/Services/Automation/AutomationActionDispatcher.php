<?php

namespace App\Services\Automation;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Http;

class AutomationActionDispatcher
{
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
            'email' => $this->dispatchEmailAction($action, $context, $meta),
            'webhook' => $this->dispatchWebhookAction($action, $context, $meta),
            default => [
                'type' => $type,
                'ok' => false,
                'message' => 'unsupported action type',
            ],
        };
    }

    /**
     * Telegram 通知动作。
     */
    private function dispatchTelegramAction(array $action, array $context, array $meta): array
    {
        $message = $this->buildMessage($action, $context, $meta);
        (new TelegramService())->sendMessageWithAdmin($message);

        return [
            'type' => 'telegram_admin',
            'ok' => true,
        ];
    }

    /**
     * 邮件通知动作。
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
                'email' => $email,
                'subject' => $subject,
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'NxPanel'),
                    'content' => $message,
                    'url' => admin_setting('app_url'),
                ],
            ]);
        }

        return [
            'type' => 'email',
            'ok' => true,
            'receiver_count' => count($receivers),
        ];
    }

    /**
     * Webhook 通知动作（支持可选签名）。
     */
    private function dispatchWebhookAction(array $action, array $context, array $meta): array
    {
        $webhookUrl = trim((string) ($action['webhookUrl'] ?? ''));
        if ($webhookUrl === '') {
            return [
                'type' => 'webhook',
                'ok' => false,
                'message' => 'webhookUrl is required',
            ];
        }

        $message = $this->buildMessage($action, $context, $meta);
        $payload = [
            'event' => (string) ($meta['event'] ?? 'triggered'),
            'module' => (string) ($meta['module'] ?? ''),
            'ruleId' => (int) ($meta['ruleId'] ?? 0),
            'ruleName' => (string) ($meta['ruleName'] ?? ''),
            'targetType' => (string) ($meta['targetType'] ?? ''),
            'targetId' => (string) ($meta['targetId'] ?? ''),
            'targetName' => (string) ($meta['targetName'] ?? ''),
            'message' => $message,
            'metrics' => $context,
            'executedAt' => now()->toDateTimeString(),
            'msg_type' => 'text',
            'content' => [
                'text' => $message,
            ],
        ];

        $timeout = max(1, (int) ($action['timeoutSeconds'] ?? 10));
        $headers = is_array($action['headers'] ?? null) ? $action['headers'] : [];
        $headers = array_merge(['Content-Type' => 'application/json'], $headers);

        $signing = is_array($action['signing'] ?? null) ? $action['signing'] : [];
        if ((int) ($signing['enabled'] ?? 0) === 1) {
            $secret = (string) ($signing['secret'] ?? '');
            if ($secret !== '') {
                $timestamp = (string) time();
                $timestampHeader = (string) ($signing['timestampHeader'] ?? 'X-Timestamp');
                $signatureHeader = (string) ($signing['signatureHeader'] ?? 'X-Signature');
                $headers[$timestampHeader] = $timestamp;
                $headers[$signatureHeader] = $this->buildFeishuSignature($timestamp, $secret);
            }
        }

        $response = Http::timeout($timeout)
            ->withHeaders($headers)
            ->post($webhookUrl, $payload);

        if (!$response->successful()) {
            throw new \RuntimeException('webhook failed: HTTP ' . $response->status() . ' body=' . mb_substr($response->body(), 0, 1000));
        }

        return [
            'type' => 'webhook',
            'ok' => true,
            'status' => $response->status(),
            'response' => mb_substr($response->body(), 0, 1000),
        ];
    }

    /**
     * 构建动作通知消息。
     */
    private function buildMessage(array $action, array $context, array $meta): string
    {
        $defaultAlertTemplate = (string) ($meta['defaultAlertTemplate'] ?? '[Automation Alert] {rule_name} | {target_name}');
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
            $key = $matches[1] ?? '';
            $value = $context[$key] ?? '';
            return is_scalar($value) || $value === null ? (string) $value : json_encode($value);
        }, $template);
    }

    /**
     * 生成飞书风格签名（可选启用）。
     */
    private function buildFeishuSignature(string $timestamp, string $secret): string
    {
        $stringToSign = $timestamp . "\n" . $secret;
        return base64_encode(hash_hmac('sha256', $stringToSign, $secret, true));
    }
}
