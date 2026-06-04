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
        $raw = Redis::lrange($this->bufferKey, 0, -1);
        Redis::del($this->bufferKey);

        if (empty($raw)) {
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
     * 根据目标 webhook 类型构建请求体。
     */
    private function buildBody(array $payloads): array
    {
        if ($this->isFeishuWebhookUrl()) {
            return $this->buildFeishuBody($payloads);
        }

        $count = count($payloads);
        if ($count === 1) {
            return $payloads[0];
        }

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
            'message' => $headerText . "\n" . $text,
            'mergedCount' => $count,
            'events' => $payloads,
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
        $body = $this->appendSigningPayload($body, $headers, $signing);

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

        $this->assertWebhookResponse($response);

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
     * 判断当前 webhook 是否为飞书自定义机器人地址。
     */
    private function isFeishuWebhookUrl(): bool
    {
        $host = (string) parse_url($this->webhookUrl, PHP_URL_HOST);
        $path = (string) parse_url($this->webhookUrl, PHP_URL_PATH);

        return in_array($host, ['open.feishu.cn', 'open.larksuite.com'], true)
            && str_starts_with($path, '/open-apis/bot/');
    }

    /**
     * 构建飞书自定义机器人消息体。
     */
    private function buildFeishuBody(array $payloads): array
    {
        $count = count($payloads);
        if ($count === 1) {
            return [
                'msg_type' => 'text',
                'content' => [
                    'text' => (string) ($payloads[0]['message'] ?? ''),
                ],
            ];
        }

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
            'msg_type' => 'text',
            'content' => [
                'text' => $headerText . "\n" . $text,
            ],
        ];
    }

    /**
     * 根据 webhook 类型附加签名信息。
     */
    private function appendSigningPayload(array $body, array &$headers, array $signing): array
    {
        if ((int) ($signing['enabled'] ?? 0) !== 1) {
            return $body;
        }

        $secret = (string) ($signing['secret'] ?? '');
        if ($secret === '') {
            return $body;
        }

        $timestamp = (string) time();
        $signature = $this->buildFeishuSignature($timestamp, $secret);

        if ($this->isFeishuWebhookUrl()) {
            $body['timestamp'] = $timestamp;
            $body['sign'] = $signature;

            return $body;
        }

        $timestampHeader = (string) ($signing['timestampHeader'] ?? 'X-Timestamp');
        $signatureHeader = (string) ($signing['signatureHeader'] ?? 'X-Signature');
        $headers[$timestampHeader] = $timestamp;
        $headers[$signatureHeader] = $signature;

        return $body;
    }

    /**
     * 校验第三方 webhook 的业务响应。
     */
    private function assertWebhookResponse(\Illuminate\Http\Client\Response $response): void
    {
        if (!$this->isFeishuWebhookUrl()) {
            return;
        }

        $json = $response->json();
        if (!is_array($json)) {
            return;
        }

        $code = $json['code'] ?? null;
        if ($code === null || (int) $code === 0) {
            return;
        }

        $message = (string) ($json['msg'] ?? 'unknown feishu webhook error');
        throw new \RuntimeException(
            'SendWebhookJob failed: Feishu business code=' . $code
            . ' message=' . $message
            . ' url=' . $this->webhookUrl
        );
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
