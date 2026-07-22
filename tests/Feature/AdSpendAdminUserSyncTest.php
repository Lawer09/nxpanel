<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdSpendAdminUserSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://adsmakeup.test';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Cache::flush();
        Setting::createOrUpdate('secure_path', 'admin');
        config()->set('services.ad_spend_admin_user_sync.enabled', false);
        config()->set('services.ad_spend_admin_user_sync.base_url', '');
        config()->set('services.ad_spend_admin_user_sync.admin_username', '');
        config()->set('services.ad_spend_admin_user_sync.admin_password', '');
        config()->set('services.ad_spend_admin_user_sync.team_id', '');
        config()->set('services.ad_spend_admin_user_sync.role_ids', []);
        $this->createPlan();
    }

    public function test_admin_generate_creates_remote_user_when_missing(): void
    {
        $admin = $this->createUser('admin-generate-owner@example.com', ['is_admin' => 1]);
        $this->enableSync();

        Http::fake([
            self::BASE_URL . '/api/auth/login' => Http::response([
                'success' => true,
                'data' => ['token' => 'admin-token'],
            ]),
            self::BASE_URL . '/api/sys/user/page*' => Http::response([
                'success' => true,
                'data' => ['records' => []],
            ]),
            self::BASE_URL . '/api/sys/user' => Http::response([
                'success' => true,
                'errorCode' => 200,
            ]),
        ]);

        $this->postJson($this->adminUserUri('generate'), [
            'email_prefix' => 'generated-admin',
            'email_suffix' => 'example.com',
            'password' => 'password123',
            'plan_id' => 1,
            'expired_at' => time() + 86400,
            'is_admin' => true,
        ], $this->adminHeaders($admin))
            ->assertOk()
            ->assertJsonPath('code', 0);

        $created = User::query()->where('email', 'generated-admin@example.com')->firstOrFail();
        $this->assertTrue((bool) $created->is_admin);

        Http::assertSent(fn($request) => $request->url() === self::BASE_URL . '/api/auth/login'
            && $request['username'] === 'provision-admin'
            && $request['password'] === 'provision-password123');

        Http::assertSent(fn($request) => str_starts_with($request->url(), self::BASE_URL . '/api/sys/user/page')
            && str_contains($request->url(), 'username=generated-admin%40example.com')
            && $request->header('Authorization') === ['Bearer admin-token']
            && $request->header('Cookie') === ['Authorization=admin-token']);

        Http::assertSent(fn($request) => $request->url() === self::BASE_URL . '/api/sys/user'
            && $request->method() === 'POST'
            && $request['username'] === 'generated-admin@example.com'
            && $request['password'] === 'password123'
            && $request['nickname'] === 'generated-admin@example.com'
            && $request['status'] === 1
            && $request['teamId'] === 'team-id-placeholder'
            && $request['roleIds'] === ['role-id-placeholder']
            && $request->header('Authorization') === ['Bearer admin-token']
            && $request->header('Cookie') === ['Authorization=admin-token']);
    }

    public function test_admin_generate_skips_remote_create_when_exact_username_exists(): void
    {
        $admin = $this->createUser('admin-generate-existing-owner@example.com', ['is_admin' => 1]);
        $this->enableSync();

        Http::fake([
            self::BASE_URL . '/api/auth/login' => Http::response([
                'success' => true,
                'data' => ['token' => 'admin-token'],
            ]),
            self::BASE_URL . '/api/sys/user/page*' => Http::response([
                'success' => true,
                'data' => [
                    'records' => [
                        ['id' => 'remote-id', 'username' => 'existing-admin@example.com'],
                    ],
                ],
            ]),
        ]);

        $this->postJson($this->adminUserUri('generate'), [
            'email_prefix' => 'existing-admin',
            'email_suffix' => 'example.com',
            'password' => 'password123',
            'plan_id' => 1,
            'is_admin' => true,
        ], $this->adminHeaders($admin))
            ->assertOk()
            ->assertJsonPath('code', 0);

        Http::assertNotSent(fn($request) => $request->url() === self::BASE_URL . '/api/sys/user'
            && $request->method() === 'POST');
    }

    public function test_remote_create_failure_prevents_local_admin_generate(): void
    {
        $admin = $this->createUser('admin-generate-fail-owner@example.com', ['is_admin' => 1]);
        $this->enableSync();

        Http::fake([
            self::BASE_URL . '/api/auth/login' => Http::response([
                'success' => true,
                'data' => ['token' => 'admin-token'],
            ]),
            self::BASE_URL . '/api/sys/user/page*' => Http::response([
                'success' => true,
                'data' => ['records' => []],
            ]),
            self::BASE_URL . '/api/sys/user' => Http::response([
                'success' => false,
                'errorMessage' => 'remote rejected',
            ]),
        ]);

        $this->postJson($this->adminUserUri('generate'), [
            'email_prefix' => 'failed-admin',
            'email_suffix' => 'example.com',
            'password' => 'password123',
            'plan_id' => 1,
            'is_admin' => true,
        ], $this->adminHeaders($admin))
            ->assertOk()
            ->assertJsonPath('code', 502);

        $this->assertFalse(User::query()->where('email', 'failed-admin@example.com')->exists());
    }

    public function test_admin_batch_generate_is_rejected(): void
    {
        $admin = $this->createUser('admin-batch-owner@example.com', ['is_admin' => 1]);
        Http::fake();

        $this->postJson($this->adminUserUri('generate'), [
            'generate_count' => 2,
            'email_suffix' => 'example.com',
            'password' => 'password123',
            'plan_id' => 1,
            'is_admin' => true,
        ], $this->adminHeaders($admin))
            ->assertOk()
            ->assertJsonPath('code', 422);

        Http::assertNothingSent();
    }

    public function test_promote_to_admin_with_password_syncs_remote_user(): void
    {
        $admin = $this->createUser('admin-update-owner@example.com', ['is_admin' => 1]);
        $user = $this->createUser('promote-admin@example.com');
        $this->enableSync();

        Http::fake([
            self::BASE_URL . '/api/auth/login' => Http::response([
                'success' => true,
                'data' => ['token' => 'admin-token'],
            ]),
            self::BASE_URL . '/api/sys/user/page*' => Http::response([
                'success' => true,
                'data' => ['records' => []],
            ]),
            self::BASE_URL . '/api/sys/user' => Http::response(['success' => true]),
        ]);

        $this->postJson($this->adminUserUri('update'), [
            'id' => $user->id,
            'is_admin' => true,
            'password' => 'newpassword123',
        ], $this->adminHeaders($admin))
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertTrue((bool) $user->fresh()->is_admin);
        Http::assertSent(fn($request) => $request->url() === self::BASE_URL . '/api/sys/user'
            && $request['username'] === 'promote-admin@example.com'
            && $request['password'] === 'newpassword123');
    }

    public function test_promote_to_admin_without_password_is_rejected(): void
    {
        $admin = $this->createUser('admin-update-no-password-owner@example.com', ['is_admin' => 1]);
        $user = $this->createUser('promote-without-password@example.com');
        Http::fake();

        $this->postJson($this->adminUserUri('update'), [
            'id' => $user->id,
            'is_admin' => true,
        ], $this->adminHeaders($admin))
            ->assertOk()
            ->assertJsonPath('code', 422);

        $this->assertFalse((bool) $user->fresh()->is_admin);
        Http::assertNothingSent();
    }

    public function test_non_admin_update_does_not_call_remote_platform(): void
    {
        $admin = $this->createUser('admin-update-normal-owner@example.com', ['is_admin' => 1]);
        $user = $this->createUser('normal-update@example.com');
        $this->enableSync();
        Http::fake();

        $this->postJson($this->adminUserUri('update'), [
            'id' => $user->id,
            'remarks' => 'updated remark',
        ], $this->adminHeaders($admin))
            ->assertOk()
            ->assertJsonPath('code', 0);

        Http::assertNothingSent();
    }

    public function test_v3_admin_login_returns_ad_spend_platform_login_data(): void
    {
        $this->createUser('login-admin@example.com', ['is_admin' => 1]);
        $this->enableSync();

        Http::fake([
            self::BASE_URL . '/api/auth/login' => Http::response([
                'success' => true,
                'data' => [
                    'token' => 'remote-user-token',
                    'userId' => 'remote-user-id',
                    'username' => 'login-admin@example.com',
                    'permissions' => ['*'],
                    'roles' => ['ADMIN'],
                ],
            ]),
        ]);

        $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'login-admin@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.ad_spend_platform_login.token', 'remote-user-token')
            ->assertJsonPath('data.ad_spend_platform_login.userId', 'remote-user-id');

        Http::assertSent(fn($request) => $request->url() === self::BASE_URL . '/api/auth/login'
            && $request['username'] === 'login-admin@example.com'
            && $request['password'] === 'password123');
    }

    public function test_v3_admin_login_creates_remote_user_when_missing_then_returns_login_data(): void
    {
        $this->createUser('login-missing-admin@example.com', ['is_admin' => 1]);
        $this->enableSync();
        $userLoginAttempts = 0;

        Http::fake(function ($request) use (&$userLoginAttempts) {
            if ($request->url() === self::BASE_URL . '/api/auth/login'
                && $request['username'] === 'login-missing-admin@example.com'
            ) {
                $userLoginAttempts++;

                if ($userLoginAttempts === 1) {
                    return Http::response([
                        'success' => false,
                        'errorMessage' => 'user not found',
                    ]);
                }

                return Http::response([
                    'success' => true,
                    'data' => [
                        'token' => 'remote-created-user-token',
                        'userId' => 'remote-created-user-id',
                        'username' => 'login-missing-admin@example.com',
                    ],
                ]);
            }

            if ($request->url() === self::BASE_URL . '/api/auth/login'
                && $request['username'] === 'provision-admin'
            ) {
                return Http::response([
                    'success' => true,
                    'data' => ['token' => 'admin-token'],
                ]);
            }

            if (str_starts_with($request->url(), self::BASE_URL . '/api/sys/user/page')) {
                return Http::response([
                    'success' => true,
                    'data' => ['records' => []],
                ]);
            }

            if ($request->url() === self::BASE_URL . '/api/sys/user'
                && $request->method() === 'POST'
            ) {
                return Http::response(['success' => true]);
            }

            return Http::response(['success' => false], 500);
        });

        $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'login-missing-admin@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.ad_spend_platform_login.token', 'remote-created-user-token')
            ->assertJsonPath('data.ad_spend_platform_login.userId', 'remote-created-user-id');

        $this->assertSame(2, $userLoginAttempts);

        Http::assertSent(fn($request) => str_starts_with($request->url(), self::BASE_URL . '/api/sys/user/page')
            && str_contains($request->url(), 'username=login-missing-admin%40example.com')
            && $request->header('Authorization') === ['Bearer admin-token']
            && $request->header('Cookie') === ['Authorization=admin-token']);

        Http::assertSent(fn($request) => $request->url() === self::BASE_URL . '/api/sys/user'
            && $request->method() === 'POST'
            && $request['username'] === 'login-missing-admin@example.com'
            && $request['password'] === 'password123'
            && $request['nickname'] === 'login-missing-admin@example.com'
            && $request['status'] === 1
            && $request['teamId'] === 'team-id-placeholder'
            && $request['roleIds'] === ['role-id-placeholder']);
    }

    public function test_v3_refresh_returns_cached_ad_spend_platform_login_data_without_password(): void
    {
        $this->createUser('refresh-admin@example.com', ['is_admin' => 1]);
        $this->enableSync();

        Http::fake([
            self::BASE_URL . '/api/auth/login' => Http::response([
                'success' => true,
                'data' => [
                    'token' => 'cached-remote-token',
                    'userId' => 'cached-remote-user-id',
                    'username' => 'refresh-admin@example.com',
                ],
            ]),
        ]);

        $login = $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'refresh-admin@example.com',
            'password' => 'password123',
        ])->assertOk();

        Http::fake();

        $this->postJson('/api/v3/passport/auth/refresh', [], [
            'Authorization' => $login->json('data.auth_data'),
        ])
            ->assertOk()
            ->assertJsonPath('data.ad_spend_platform_login.token', 'cached-remote-token')
            ->assertJsonPath('data.ad_spend_platform_login.userId', 'cached-remote-user-id');

        Http::assertNothingSent();
    }

    public function test_v3_refresh_with_only_auth_data_returns_login_response_shape(): void
    {
        $this->createUser('refresh-body-admin@example.com', ['is_admin' => 1]);
        $this->enableSync();

        Http::fake([
            self::BASE_URL . '/api/auth/login' => Http::response([
                'success' => true,
                'data' => [
                    'token' => 'body-cached-remote-token',
                    'userId' => 'body-cached-remote-user-id',
                    'username' => 'refresh-body-admin@example.com',
                ],
            ]),
        ]);

        $login = $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'refresh-body-admin@example.com',
            'password' => 'password123',
        ])->assertOk();

        Http::fake();

        $response = $this->postJson('/api/v3/passport/auth/refresh', [
            'auth_data' => $login->json('data.auth_data'),
        ])->assertOk();

        $response->assertJsonPath('code', 0)
            ->assertJsonPath('data.is_admin', 1)
            ->assertJsonPath('data.secure_path', 'admin')
            ->assertJsonPath('data.user_type', 'global')
            ->assertJsonPath('data.menus', [])
            ->assertJsonPath('data.ad_spend_platform_login.token', 'body-cached-remote-token')
            ->assertJsonPath('data.ad_spend_platform_login.userId', 'body-cached-remote-user-id');

        $this->assertIsString($response->json('data.token'));
        $this->assertIsString($response->json('data.auth_data'));
        $this->assertStringStartsWith('Bearer ', $response->json('data.auth_data'));
        Http::assertNothingSent();
    }

    public function test_v3_refresh_accepts_client_held_ad_spend_platform_token(): void
    {
        $admin = $this->createUser('refresh-token-admin@example.com', ['is_admin' => 1]);
        $this->enableSync();
        Http::fake();

        $this->postJson('/api/v3/passport/auth/refresh', [
            'ad_spend_platform_token' => 'client-held-remote-token',
        ], [
            'Authorization' => (new AuthService($admin))->generateAuthData()['auth_data'],
        ])
            ->assertOk()
            ->assertJsonPath('data.ad_spend_platform_login.token', 'client-held-remote-token');

        Http::assertNothingSent();
    }

    public function test_v3_non_admin_login_does_not_return_ad_spend_platform_login_data(): void
    {
        $this->createUser('login-user@example.com');
        $this->enableSync();
        Http::fake();

        $response = $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'login-user@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertArrayNotHasKey('ad_spend_platform_login', $response->json('data'));
        Http::assertNothingSent();
    }

    public function test_v3_admin_login_keeps_local_login_when_remote_login_fails(): void
    {
        $this->createUser('login-admin-remote-fail@example.com', ['is_admin' => 1]);
        $this->enableSync();

        Http::fake([
            self::BASE_URL . '/api/auth/login' => Http::response([
                'success' => false,
                'errorMessage' => 'remote rejected',
            ]),
        ]);

        $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'login-admin-remote-fail@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.ad_spend_platform_login', null);
    }

    public function test_v1_and_v2_admin_login_response_shape_is_unchanged(): void
    {
        $this->createUser('legacy-admin@example.com', ['is_admin' => 1]);
        $this->enableSync();
        Http::fake();

        $v1 = $this->postJson('/api/v1/passport/auth/login', [
            'email' => 'legacy-admin@example.com',
            'password' => 'password123',
        ])->assertOk();

        $v2 = $this->postJson('/api/v2/passport/auth/login', [
            'email' => 'legacy-admin@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertArrayNotHasKey('ad_spend_platform_login', $v1->json('data'));
        $this->assertArrayNotHasKey('ad_spend_platform_login', $v2->json('data'));
        Http::assertNothingSent();
    }

    private function enableSync(): void
    {
        config()->set('services.ad_spend_admin_user_sync.enabled', true);
        config()->set('services.ad_spend_admin_user_sync.base_url', self::BASE_URL);
        config()->set('services.ad_spend_admin_user_sync.admin_username', 'provision-admin');
        config()->set('services.ad_spend_admin_user_sync.admin_password', 'provision-password123');
        config()->set('services.ad_spend_admin_user_sync.team_id', 'team-id-placeholder');
        config()->set('services.ad_spend_admin_user_sync.role_ids', ['role-id-placeholder']);
        config()->set('services.ad_spend_admin_user_sync.timeout_seconds', 5);
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
            'is_admin' => 0,
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
