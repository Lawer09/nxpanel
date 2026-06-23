<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserTypeLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->createPlan();
    }

    public function test_v1_password_login_returns_default_user_type(): void
    {
        $this->createUser('v1-user-type@example.com');

        $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'v1-user-type@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user_type', 'global');
    }

    public function test_v3_password_login_returns_default_user_type(): void
    {
        $this->createUser('v3-user-type@example.com');

        $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'v3-user-type@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user_type', 'global');
    }

    public function test_password_login_returns_custom_user_type(): void
    {
        $this->createUser('custom-user-type@example.com', [
            'user_type' => 'custom_value',
        ]);

        $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'custom-user-type@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user_type', 'custom_value');
    }

    public function test_aid_login_does_not_return_user_type(): void
    {
        $response = $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'user-type-aid',
            'metadata' => [
                'app_id' => 'com.example.app',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', false);

        $this->assertArrayNotHasKey('user_type', $response->json('data'));
    }

    private function createUser(string $email, array $overrides = []): User
    {
        return User::create(array_replace([
            'email' => $email,
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'plan_id' => 1,
            'group_id' => 1,
            'expired_at' => time() + 86400,
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 1024 * 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
        ], $overrides));
    }

    private function createPlan(): Plan
    {
        return Plan::query()->forceCreate([
            'id' => 1,
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
        ]);
    }
}
