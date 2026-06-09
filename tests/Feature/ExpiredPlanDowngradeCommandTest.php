<?php

namespace Tests\Feature;

use App\Console\Kernel;
use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class ExpiredPlanDowngradeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_command_downgrades_expired_user_to_plan_id_one_and_resets_traffic(): void
    {
        $freePlan = $this->createPlan([
            'id' => 1,
            'name' => 'Starter',
            'group_id' => 11,
            'transfer_enable' => 3,
            'speed_limit' => 30,
            'device_limit' => 2,
        ]);
        $paidPlan = $this->createPlan([
            'id' => 2,
            'name' => 'Pro',
            'group_id' => 22,
            'transfer_enable' => 30,
            'speed_limit' => 300,
            'device_limit' => 6,
        ]);

        $user = $this->createExpiredUser($paidPlan, [
            'u' => 1024,
            'd' => 2048,
            'next_reset_at' => time() - 10,
        ]);

        $this->artisan('subscription:downgrade-expired-to-free')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertSame($freePlan->id, $user->plan_id);
        $this->assertSame($freePlan->group_id, $user->group_id);
        $this->assertSame($freePlan->transfer_enable * 1073741824, $user->transfer_enable);
        $this->assertSame($freePlan->speed_limit, $user->speed_limit);
        $this->assertSame($freePlan->device_limit, $user->device_limit);
        $this->assertNull($user->expired_at);
        $this->assertSame(0, $user->u);
        $this->assertSame(0, $user->d);
        $this->assertNull($user->next_reset_at);

        $this->assertDatabaseHas('v2_traffic_reset_logs', [
            'user_id' => $user->id,
            'trigger_source' => TrafficResetLog::SOURCE_CRON,
        ]);

        $log = TrafficResetLog::query()->where('user_id', $user->id)->latest('id')->first();
        $this->assertSame('expired_plan_downgrade', $log?->metadata['reason'] ?? null);
        $this->assertSame($paidPlan->id, $log?->metadata['from_plan_id'] ?? null);
        $this->assertSame($freePlan->id, $log?->metadata['to_plan_id'] ?? null);
    }

    public function test_command_falls_back_to_name_free_when_plan_id_one_is_missing(): void
    {
        $freePlan = $this->createPlan([
            'id' => 2,
            'name' => 'Free',
            'group_id' => 33,
            'transfer_enable' => 5,
        ]);
        $paidPlan = $this->createPlan([
            'id' => 3,
            'name' => 'Premium',
        ]);

        $user = $this->createExpiredUser($paidPlan);

        $this->artisan('subscription:downgrade-expired-to-free')
            ->assertExitCode(0);

        $this->assertSame($freePlan->id, $user->fresh()->plan_id);
    }

    public function test_command_falls_back_to_name_free_in_chinese_when_needed(): void
    {
        $freePlan = $this->createPlan([
            'id' => 2,
            'name' => '免费',
            'group_id' => 44,
            'transfer_enable' => 6,
        ]);
        $paidPlan = $this->createPlan([
            'id' => 3,
            'name' => 'Business',
        ]);

        $user = $this->createExpiredUser($paidPlan);

        $this->artisan('subscription:downgrade-expired-to-free')
            ->assertExitCode(0);

        $this->assertSame($freePlan->id, $user->fresh()->plan_id);
    }

    public function test_command_keeps_current_logic_when_no_free_plan_exists(): void
    {
        $paidPlan = $this->createPlan([
            'id' => 2,
            'name' => 'Paid Only',
        ]);

        $user = $this->createExpiredUser($paidPlan);
        $originalExpiredAt = $user->expired_at;

        $this->artisan('subscription:downgrade-expired-to-free')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertSame($paidPlan->id, $user->plan_id);
        $this->assertSame($originalExpiredAt, $user->expired_at);
        $this->assertSame(0, TrafficResetLog::count());
    }

    public function test_command_repairs_expired_user_already_on_free_plan(): void
    {
        $freePlan = $this->createPlan([
            'id' => 1,
            'name' => 'Free',
            'transfer_enable' => 8,
            'device_limit' => 1,
        ]);

        $user = $this->createExpiredUser($freePlan, [
            'u' => 500,
            'd' => 700,
        ]);

        $this->artisan('subscription:downgrade-expired-to-free')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertSame($freePlan->id, $user->plan_id);
        $this->assertNull($user->expired_at);
        $this->assertSame(0, $user->u);
        $this->assertSame(0, $user->d);
    }

    public function test_command_does_not_touch_unexpired_user(): void
    {
        $freePlan = $this->createPlan([
            'id' => 1,
            'name' => 'Free',
        ]);
        $paidPlan = $this->createPlan([
            'id' => 2,
            'name' => 'VIP',
        ]);

        $user = $this->createUserForPlan($paidPlan, [
            'expired_at' => time() + 3600,
            'u' => 321,
            'd' => 654,
        ]);

        $this->artisan('subscription:downgrade-expired-to-free')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertSame($paidPlan->id, $user->plan_id);
        $this->assertSame(321, $user->u);
        $this->assertSame(654, $user->d);
        $this->assertNotSame($freePlan->id, $user->plan_id);
    }

    public function test_kernel_schedules_downgrade_command_every_minute(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $kernel = $this->app->make(Kernel::class);

        $method = new ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        $matched = collect($schedule->events())->first(function ($event) {
            return str_contains((string) $event->command, 'subscription:downgrade-expired-to-free')
                && $event->expression === '* * * * *';
        });

        $this->assertNotNull($matched);
    }

    private function createPlan(array $overrides = []): Plan
    {
        return Plan::query()->forceCreate(array_replace([
            'group_id' => 1,
            'transfer_enable' => 10,
            'name' => 'Plan',
            'speed_limit' => 100,
            'show' => true,
            'renew' => true,
            'sell' => true,
            'prices' => ['monthly' => 999],
            'sort' => 1,
            'device_limit' => 3,
        ], $overrides));
    }

    private function createExpiredUser(Plan $plan, array $overrides = []): User
    {
        return $this->createUserForPlan($plan, array_replace([
            'expired_at' => time() - 60,
        ], $overrides));
    }

    private function createUserForPlan(Plan $plan, array $overrides = []): User
    {
        return User::create(array_replace([
            'email' => Helper::guid() . '@example.com',
            'password' => password_hash('secret', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => $plan->transfer_enable * 1073741824,
            'speed_limit' => $plan->speed_limit,
            'device_limit' => $plan->device_limit,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'balance' => 0,
            'commission_balance' => 0,
        ], $overrides));
    }
}
