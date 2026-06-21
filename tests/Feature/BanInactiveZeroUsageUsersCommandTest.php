<?php

namespace Tests\Feature;

use App\Console\Kernel;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class BanInactiveZeroUsageUsersCommandTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-21 12:00:00', 'Asia/Shanghai'));
        Queue::fake();
        $this->freePlan = $this->createPlan([
            'id' => 1,
            'name' => 'Free',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_command_bans_old_zero_usage_user_with_no_recent_report_activity(): void
    {
        $candidate = $this->createUser([
            'created_at' => now()->subDays(8)->timestamp,
            'updated_at' => now()->subDays(8)->timestamp,
        ]);

        $this->artisan('user:ban-inactive-zero-usage')
            ->assertExitCode(0);

        $this->assertTrue((bool) $candidate->fresh()->banned);
    }

    public function test_command_keeps_users_with_usage_recent_report_activity_or_new_registration(): void
    {
        $usedUser = $this->createUser([
            'u' => 1024,
            'created_at' => now()->subDays(8)->timestamp,
            'updated_at' => now()->subDays(8)->timestamp,
        ]);
        $recentTrafficUser = $this->createUser([
            'created_at' => now()->subDays(8)->timestamp,
            'updated_at' => now()->subDays(8)->timestamp,
        ]);
        $recentReportUser = $this->createUser([
            'created_at' => now()->subDays(8)->timestamp,
            'updated_at' => now()->subDays(8)->timestamp,
        ]);
        $newUser = $this->createUser([
            'created_at' => now()->subDays(3)->timestamp,
            'updated_at' => now()->subDays(3)->timestamp,
        ]);
        $olderUser = $this->createUser([
            'created_at' => now()->subDays(9)->timestamp,
            'updated_at' => now()->subDays(9)->timestamp,
        ]);
        $paidPlan = $this->createPlan([
            'id' => 2,
            'name' => 'Pro',
        ]);
        $paidUser = $this->createUser([
            'plan_id' => $paidPlan->id,
            'created_at' => now()->subDays(8)->timestamp,
            'updated_at' => now()->subDays(8)->timestamp,
        ]);

        $this->insertHourlyReport($recentTrafficUser->id, [
            'traffic_usage' => 1,
        ]);
        $this->insertHourlyReport($recentReportUser->id, [
            'report_count_user' => 1,
        ]);

        $this->artisan('user:ban-inactive-zero-usage')
            ->assertExitCode(0);

        $this->assertFalse((bool) $usedUser->fresh()->banned);
        $this->assertFalse((bool) $recentTrafficUser->fresh()->banned);
        $this->assertFalse((bool) $recentReportUser->fresh()->banned);
        $this->assertFalse((bool) $newUser->fresh()->banned);
        $this->assertFalse((bool) $olderUser->fresh()->banned);
        $this->assertFalse((bool) $paidUser->fresh()->banned);
    }

    public function test_kernel_schedules_inactive_zero_usage_ban_daily(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $kernel = $this->app->make(Kernel::class);

        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        $matched = collect($schedule->events())->first(function ($event) {
            return str_contains((string) $event->command, 'user:ban-inactive-zero-usage')
                && $event->expression === '30 1 * * *';
        });

        $this->assertNotNull($matched);
    }

    private function createUser(array $overrides = []): User
    {
        return User::create(array_replace([
            'email' => Helper::guid() . '@example.com',
            'password' => password_hash('secret', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'plan_id' => $this->freePlan->id,
            'group_id' => $this->freePlan->group_id,
            'transfer_enable' => 1024 * 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'balance' => 0,
            'commission_balance' => 0,
        ], $overrides));
    }

    private function createPlan(array $overrides = []): Plan
    {
        return Plan::query()->forceCreate(array_replace([
            'group_id' => 1,
            'transfer_enable' => 10,
            'name' => 'Free',
            'speed_limit' => 100,
            'show' => true,
            'renew' => true,
            'sell' => true,
            'prices' => ['monthly' => 0],
            'sort' => 1,
            'device_limit' => 3,
        ], $overrides));
    }

    private function insertHourlyReport(int $userId, array $overrides = []): void
    {
        DB::table('v3_report_user_hourly')->insert(array_replace([
            'date' => now()->subDay()->toDateString(),
            'hour' => 10,
            'user_id' => $userId,
            'app_id' => '',
            'app_version' => '',
            'country' => '',
            'traffic_usage' => 0,
            'traffic_use_time' => 0,
            'traffic_upload' => 0,
            'traffic_download' => 0,
            'report_count_user' => 0,
            'report_count_node' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
