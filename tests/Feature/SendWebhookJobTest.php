<?php

namespace Tests\Feature;

use App\Jobs\SendWebhookJob;
use ReflectionMethod;
use Tests\TestCase;

class SendWebhookJobTest extends TestCase
{
    /**
     * 验证 webhook 动作配置 method=PUT 时，实际请求会使用 PUT。
     */
    public function test_send_webhook_job_respects_configured_put_method(): void
    {
        $job = new SendWebhookJob('https://example.com/webhook', 'automation:webhook:buffer:test', [
            'method' => 'PUT',
        ]);

        $this->assertSame('PUT', $this->resolveWebhookMethod($job));
    }

    /**
     * 验证未配置 method 时，webhook 默认按 POST 发送。
     */
    public function test_send_webhook_job_defaults_to_post_method(): void
    {
        $job = new SendWebhookJob('https://example.com/default-webhook', 'automation:webhook:buffer:default');

        $this->assertSame('POST', $this->resolveWebhookMethod($job));
    }

    private function resolveWebhookMethod(SendWebhookJob $job): string
    {
        $method = new ReflectionMethod($job, 'resolveMethod');
        $method->setAccessible(true);

        return $method->invoke($job);
    }
}
