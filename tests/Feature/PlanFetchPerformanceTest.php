<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanFetchPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_fetch_filters_capacity_limited_plans_with_active_user_counts(): void
    {
        $currentUser = $this->createUser('current@example.com');
        $unlimitedPlan = $this->createPlan([
            'name' => 'Unlimited',
            'capacity_limit' => null,
            'sort' => 1,
        ]);
        $soldOutPlan = $this->createPlan([
            'name' => 'Sold Out',
            'capacity_limit' => 0,
            'sort' => 2,
        ]);
        $availableLimitedPlan = $this->createPlan([
            'name' => 'Available Limited',
            'capacity_limit' => 2,
            'sort' => 3,
        ]);
        $fullLimitedPlan = $this->createPlan([
            'name' => 'Full Limited',
            'capacity_limit' => 1,
            'sort' => 4,
        ]);

        $this->createUser('active-limited@example.com', [
            'plan_id' => $availableLimitedPlan->id,
            'expired_at' => time() + 86400,
        ]);
        $this->createUser('expired-limited@example.com', [
            'plan_id' => $availableLimitedPlan->id,
            'expired_at' => time() - 86400,
        ]);
        $this->createUser('active-full@example.com', [
            'plan_id' => $fullLimitedPlan->id,
            'expired_at' => null,
        ]);

        $response = $this->getJson('/api/v3/user/plan/fetch', [
            'Authorization' => $this->authData($currentUser),
        ]);

        $response->assertOk();

        $planIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($unlimitedPlan->id, $planIds);
        $this->assertContains($availableLimitedPlan->id, $planIds);
        $this->assertNotContains($soldOutPlan->id, $planIds);
        $this->assertNotContains($fullLimitedPlan->id, $planIds);
    }

    public function test_single_plan_fetch_keeps_existing_capacity_behavior(): void
    {
        $currentUser = $this->createUser('current@example.com');
        $fullLimitedPlan = $this->createPlan([
            'name' => 'Full Limited',
            'capacity_limit' => 1,
        ]);
        $this->createUser('active-full@example.com', [
            'plan_id' => $fullLimitedPlan->id,
            'expired_at' => null,
        ]);

        $this->getJson('/api/v3/user/plan/fetch?id=' . $fullLimitedPlan->id, [
            'Authorization' => $this->authData($currentUser),
        ])->assertJsonPath('code', 400);
    }

    private function createPlan(array $overrides = []): Plan
    {
        return Plan::query()->forceCreate(array_replace([
            'group_id' => 1,
            'transfer_enable' => 1,
            'name' => 'Plan',
            'show' => true,
            'renew' => true,
            'sell' => true,
            'prices' => ['monthly' => 0],
            'sort' => 1,
        ], $overrides));
    }

    private function createUser(string $email, array $overrides = []): User
    {
        return User::create(array_replace([
            'email' => $email,
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'expired_at' => time() + 86400,
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 1024 * 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
        ], $overrides));
    }

    private function authData(User $user): string
    {
        return (new AuthService($user))->generateAuthData()['auth_data'];
    }
}
