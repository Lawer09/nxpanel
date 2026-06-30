<?php

namespace Tests\Feature;

use App\Console\Kernel;
use App\Jobs\SendWebhookJob;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use ReflectionMethod;
use Tests\TestCase;

class ProjectYesterdayTrafficReportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-30 10:00:00', 'Asia/Shanghai'));
        config()->set('services.feishu.project_traffic_report_webhook_url', 'https://open.feishu.cn/open-apis/bot/v2/hook/test-token');
        config()->set('services.feishu.project_traffic_report_timeout_seconds', 7);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Verify active projects are reported, inactive projects are excluded, and missing traffic is shown as 0 GB.
     */
    public function test_command_reports_only_active_projects_and_includes_zero_usage_projects(): void
    {
        Queue::fake();
        $capturedPayload = null;
        Redis::shouldReceive('rpush')
            ->once()
            ->withArgs(function (string $key, string $payload) use (&$capturedPayload): bool {
                $capturedPayload = json_decode($payload, true);

                return str_starts_with($key, 'project:traffic_report:webhook:')
                    && is_array($capturedPayload);
            })
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $this->insertProject('A001', 'Alpha');
        $this->insertProject('A002', 'Beta');
        $this->insertProject('X001', 'Inactive', 'inactive');
        $this->insertDailyAggregate('2026-06-29', 'A001', 'US', 2048);
        $this->insertDailyAggregate('2026-06-29', 'X001', 'US', 4096);

        $this->artisan('project:send-yesterday-traffic-report')
            ->assertExitCode(0);

        $message = (string) ($capturedPayload['message'] ?? '');
        $this->assertStringContainsString('统计日期：2026-06-29', $message);
        $this->assertStringContainsString('项目数量：2', $message);
        $this->assertStringContainsString('总流量：2.00 GB', $message);
        $this->assertStringContainsString('- Alpha（A001）：2.00 GB', $message);
        $this->assertStringContainsString('- Beta（A002）：0.00 GB', $message);
        $this->assertStringNotContainsString('Inactive', $message);

        Queue::assertPushed(SendWebhookJob::class);
    }

    /**
     * Verify MB traffic is displayed as GB by dividing by 1024 and formatting to two decimals.
     */
    public function test_command_formats_traffic_usage_as_gb_with_two_decimals(): void
    {
        Queue::fake();
        $capturedPayload = null;
        Redis::shouldReceive('rpush')
            ->once()
            ->withArgs(function (string $key, string $payload) use (&$capturedPayload): bool {
                $capturedPayload = json_decode($payload, true);

                return str_starts_with($key, 'project:traffic_report:webhook:')
                    && is_array($capturedPayload);
            })
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $this->insertProject('A001', 'Alpha');
        $this->insertProject('A002', 'Beta');
        $this->insertDailyAggregate('2026-06-28', 'A001', 'US', 1536);
        $this->insertDailyAggregate('2026-06-28', 'A001', 'JP', 512);
        $this->insertDailyAggregate('2026-06-28', 'A002', 'US', 1);

        $this->artisan('project:send-yesterday-traffic-report --date=2026-06-28')
            ->assertExitCode(0);

        $message = (string) ($capturedPayload['message'] ?? '');
        $this->assertStringContainsString('统计日期：2026-06-28', $message);
        $this->assertStringContainsString('- Alpha（A001）：2.00 GB', $message);
        $this->assertStringContainsString('- Beta（A002）：0.00 GB', $message);
        $this->assertStringContainsString('总流量：2.00 GB', $message);
    }

    /**
     * Verify missing webhook configuration fails explicitly.
     */
    public function test_command_fails_when_webhook_url_is_missing(): void
    {
        Queue::fake();
        config()->set('services.feishu.project_traffic_report_webhook_url', '');

        $this->artisan('project:send-yesterday-traffic-report')
            ->expectsOutput('Missing FEISHU_PROJECT_TRAFFIC_REPORT_WEBHOOK_URL.')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /**
     * Verify the daily report command is scheduled at 09:30.
     */
    public function test_kernel_schedules_project_yesterday_traffic_report_daily_at_0930(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $kernel = $this->app->make(Kernel::class);

        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        $matched = collect($schedule->events())->first(function ($event) {
            return str_contains((string) $event->command, 'project:send-yesterday-traffic-report')
                && $event->expression === '30 9 * * *';
        });

        $this->assertNotNull($matched);
    }

    private function insertProject(string $code, string $name, string $status = 'active'): void
    {
        DB::table('project_projects')->insert([
            'project_code' => $code,
            'project_name' => $name,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDailyAggregate(string $date, string $projectCode, string $country, float $trafficUsageMb): void
    {
        DB::table('project_daily_aggregates')->insert([
            'report_date' => $date,
            'project_code' => $projectCode,
            'country' => $country,
            'dau_users' => 0,
            'new_users' => 0,
            'report_new_users' => 0,
            'fb_new_users' => 0,
            'fb_dau_users' => 0,
            'ad_revenue' => 0,
            'ad_requests' => 0,
            'ad_matched_requests' => 0,
            'ad_impressions' => 0,
            'ad_clicks' => 0,
            'ad_spend_cost' => 0,
            'traffic_usage_mb' => $trafficUsageMb,
            'traffic_cost' => 0,
            'profit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
