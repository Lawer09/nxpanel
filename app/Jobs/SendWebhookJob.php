<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [5, 15, 60];

    /**
     * @param string $webhookUrl  目标 webhook 地址
     * @param string $bufferKey   Redis 缓冲区 Key（对应该 webhookUrl 的 payload 列表）
     * @param array  $action      动作配置（headers / signing / timeoutSeconds 等）
     */
    public function __construct(
        private readonly string $webhookUrl,
        private readonly string $bufferKey,
        private readonly array $action = []
    ) {
        $this->onQueue('send_webhook');
    }

    /**
     * 从 Redis 缓冲区取出全部 payload，合并后发送到 webhookUrl。
     */
    public function handle(): void
    {
        // 原子取出并清空缓冲区，避免重复消费。
        $raw = Redis::lrange($this->bufferKey, 0, -1);
        Redis::del($this->bufferKey);

        if (empty($raw)) {
            // 已被其他 Job 消费（极端竞争场景），安全退出。
            return;
        }

        $payloads = array_values(array_filter(array_map(static function (string $item): ?array {
            $decoded = json_decode($item, true);
            return is_array($decoded) ? $decoded : null;
        }, $raw)));

        if (empty($payloads)) {
            return;
        }

        $body = $this->buildBody($payloads);
        $this->sendRequest($body);
    }

    /**
     * 构建发送 body，单条保持原结构，多条追加 events[] 摘要。
     */
    private function buildBody(array $payloads): array
    {
        $count = count($payloads);

        if ($count === 1) {
            return $payloads[0];
        }

        // 多条合并：将每条 message 拼接为飞书文本。
        $lines = [];
        foreach ($payloads as $index => $payload) {
            $event = (string) ($payload['event'] ?? 'triggered');
            $label = $event === 'recovered' ? '[Recovered]' : '[Alert]';
            $message = (string) ($payload['message'] ?? '');
            $lines[] = ($index + 1) . ". {$label} {$message}";
        }

        $text = implode("\n", $lines);

        $alertCount = count(array_filter($payloads, fn ($p) => ($p['event'] ?? '') !== 'recovered'));
        $recoverCount = $count - $alertCount;
        $summary = [];
        if ($alertCount > 0) {
            $summary[] = "{$alertCount} alert(s)";
        }
        if ($recoverCount > 0) {
            $summary[] = "{$recoverCount} recovery(s)";
        }

        $headerText = '[Automation] ' . implode(', ', $summary) . " ({$count} total)";

        return [
            // 飞书 text 消息兼容字段。
            'msg_type' => 'text',
            'content' => [
                'text' => $headerText . "\n" . $text,
            ],
            // 扩展字段，供非飞书系统使用。
            'mergedCount' => $count,
            'events' => array_map(static fn ($p) => [
                'event' => $p['event'] ?? '',
                'module' => $p['module'] ?? '',
                'ruleId' => $p['ruleId'] ?? null,
                'ruleName' => $p['ruleName'] ?? '',
                'targetType' => $p['targetType'] ?? '',
                'targetId' => $p['targetId'] ?? '',
                'targetName' => $p['targetName'] ?? '',
                'message' => $p['message'] ?? '',
                'executedAt' => $p['executedAt'] ?? '',
            ], $payloads),
        ];
    }

    /**
     * 执行 HTTP 请求，含可选签名。
     */
    private function sendRequest(array $body): void
    {
        $method = $this->resolveMethod();
        $timeout = max(1, (int) ($this->action['timeoutSeconds'] ?? 10));
        $headers = is_array($this->action['headers'] ?? null) ? $this->action['headers'] : [];
        $headers = array_merge(['Content-Type' => 'application/json'], $headers);

        $signing = is_array($this->action['signing'] ?? null) ? $this->action['signing'] : [];
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
            ->send($method, $this->webhookUrl, [
                'json' => $body,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'SendWebhookJob failed: HTTP ' . $response->status()
                . ' method=' . $method
                . ' url=' . $this->webhookUrl
                . ' body=' . mb_substr($response->body(), 0, 500)
            );
        }

        Log::info('SendWebhookJob sent', [
            'method' => $method,
            'url' => $this->webhookUrl,
            'merged_count' => $body['mergedCount'] ?? 1,
            'status' => $response->status(),
        ]);
    }

    /**
     * 解析并规范化 webhook 请求方法。
     */
    private function resolveMethod(): string
    {
        $method = strtoupper((string) ($this->action['method'] ?? 'POST'));

        return in_array($method, ['POST', 'PUT', 'PATCH'], true) ? $method : 'POST';
    }

    /**
     * 生成飞书风格签名：base64(hmac_sha256(timestamp+"\n"+secret, secret))。
     */
    private function buildFeishuSignature(string $timestamp, string $secret): string
    {
        $stringToSign = $timestamp . "\n" . $secret;
        return base64_encode(hash_hmac('sha256', $stringToSign, $secret, true));
    }
}
