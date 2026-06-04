<?php

namespace Tests\Feature;

use App\Jobs\SendWebhookJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SendWebhookJobTest extends TestCase
{
    /**
     * 验证 webhook 动作配置 method=PUT 时，实际请求会使用 PUT。
     */
    public function test_send_webhook_job_respects_configured_put_method(): void
    {
        Http::fake([
            'https://example.com/webhook' => Http::response(['ok' => true], 200),
        ]);

        Redis::shouldReceive('lrange')
            ->once()
            ->with('automation:webhook:buffer:test', 0, -1)
            ->andReturn([
                json_encode([
                    'event' => 'triggered',
                    'message' => 'alert',
                    'executedAt' => '2026-06-04 12:00:00',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        Redis::shouldReceive('del')
            ->once()
            ->with('automation:webhook:buffer:test')
            ->andReturn(1);

        $job = new SendWebhookJob('https://example.com/webhook', 'automation:webhook:buffer:test', [
            'method' => 'PUT',
        ]);
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/webhook'
                && $request->method() === 'PUT'
                && $request['event'] === 'triggered';
        });
    }

    /**
     * 验证未配置 method 时，webhook 默认按 POST 发送。
     */
    public function test_send_webhook_job_defaults_to_post_method(): void
    {
        Http::fake([
            'https://example.com/default-webhook' => Http::response(['ok' => true], 200),
        ]);

        Redis::shouldReceive('lrange')
            ->once()
            ->with('automation:webhook:buffer:default', 0, -1)
            ->andReturn([
                json_encode([
                    'event' => 'triggered',
                    'message' => 'alert',
                    'executedAt' => '2026-06-04 12:05:00',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        Redis::shouldReceive('del')
            ->once()
            ->with('automation:webhook:buffer:default')
            ->andReturn(1);

        $job = new SendWebhookJob('https://example.com/default-webhook', 'automation:webhook:buffer:default');
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/default-webhook'
                && $request->method() === 'POST'
                && $request['event'] === 'triggered';
        });
    }
}
