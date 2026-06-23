<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserTypeLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::createOrUpdate('secure_path', 'admin');
        $this->createPlan();
    }

    public function test_v1_password_login_returns_default_user_type(): void
    {
        $this->createUser('v1-user-type@example.com');

        $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'v1-user-type@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user_type', 'global')
            ->assertJsonPath('data.menus', []);
    }

    public function test_v3_password_login_returns_default_user_type(): void
    {
        $this->createUser('v3-user-type@example.com');

        $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'v3-user-type@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user_type', 'global')
            ->assertJsonPath('data.menus', []);
    }

    public function test_v2_password_login_returns_default_user_type_and_menus(): void
    {
        $this->createUser('v2-user-type@example.com');

        $this->postJson('/api/v2/passport/auth/login', [
            'email' => 'v2-user-type@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user_type', 'global')
            ->assertJsonPath('data.menus', []);
    }

    public function test_password_login_returns_custom_user_type_and_menus(): void
    {
        $this->createUser('custom-user-type@example.com', [
            'user_type' => 'custom_value',
            'menus' => ['dashboard', 'reports'],
        ]);

        $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'custom-user-type@example.com',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('data.user_type', 'custom_value')
            ->assertJsonPath('data.menus', ['dashboard', 'reports']);
    }

    public function test_aid_login_does_not_return_password_login_fields(): void
    {
        $response = $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'user-type-aid',
            'metadata' => [
                'app_id' => 'com.example.app',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', false);

        $this->assertArrayNotHasKey('user_type', $response->json('data'));
        $this->assertArrayNotHasKey('menus', $response->json('data'));
    }

    public function test_admin_generate_user_persists_user_type_and_menus(): void
    {
        $admin = $this->createUser('admin-generate@example.com', ['is_admin' => 1]);

        $this->postJson($this->adminUserUri('generate'), [
            'email_prefix' => 'generated-user-fields',
            'email_suffix' => 'example.com',
            'password' => 'password123',
            'plan_id' => 1,
            'expired_at' => time() + 86400,
            'user_type' => 'custom_generate',
            'menus' => ['dashboard', 'users'],
        ], $this->adminHeaders($admin))->assertOk();

        $user = User::query()->where('email', 'generated-user-fields@example.com')->firstOrFail();

        $this->assertSame('custom_generate', $user->user_type);
        $this->assertSame(['dashboard', 'users'], $user->menus);
    }

    public function test_admin_update_user_persists_user_type_and_menus(): void
    {
        $admin = $this->createUser('admin-update@example.com', ['is_admin' => 1]);
        $user = $this->createUser('updated-user-fields@example.com');

        $this->postJson($this->adminUserUri('update'), [
            'id' => $user->id,
            'user_type' => 'custom_update',
            'menus' => ['reports', 'settings'],
        ], $this->adminHeaders($admin))->assertOk();

        $user->refresh();

        $this->assertSame('custom_update', $user->user_type);
        $this->assertSame(['reports', 'settings'], $user->menus);
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

    private function adminHeaders(User $admin): array
    {
        return [
            'Authorization' => (new AuthService($admin))->generateAuthData()['auth_data'],
        ];
    }

    private function adminUserUri(string $action): string
    {
        $suffix = 'user/' . trim($action, '/');

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v3/') && str_ends_with($route->uri(), $suffix)) {
                return '/' . $route->uri();
            }
        }

        return '/api/v3/admin/' . $suffix;
    }
}
