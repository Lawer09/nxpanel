<?php

namespace Tests\Feature;

use App\Models\BlockedUserIp;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserIpBanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        Setting::createOrUpdate('secure_path', 'admin');
        $this->createPlan(['id' => 1, 'name' => 'Free']);
    }

    public function test_login_by_aid_stores_metadata_ip(): void
    {
        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'device-001',
            'metadata' => [
                'app_id' => 'com.example.app',
                'ip' => '203.0.113.10',
            ],
        ])->assertOk();

        $user = User::query()->where('email', 'device-001@apple.com')->firstOrFail();

        $this->assertSame('203.0.113.10', $user->register_metadata['ip'] ?? null);
        $this->assertFalse((bool) $user->banned);
    }

    public function test_login_by_aid_bans_new_user_when_ip_is_blocked(): void
    {
        BlockedUserIp::create([
            'ip' => '203.0.113.20',
            'reason' => 'known abusive IP',
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'device-002',
            'metadata' => [
                'app_id' => 'com.example.app',
                'ip' => '203.0.113.20',
            ],
        ])->assertStatus(400);

        $user = User::query()->where('email', 'device-002@apple.com')->firstOrFail();

        $this->assertTrue((bool) $user->banned);
        $this->assertSame('203.0.113.20', $user->register_metadata['ip'] ?? null);
    }

    public function test_admin_batch_ban_blocks_registration_ips(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);
        $userWithIp = $this->createUser('with-ip@example.com', [
            'register_metadata' => ['ip' => '203.0.113.30', 'app_id' => 'com.example.app'],
        ]);
        $userWithoutIp = $this->createUser('without-ip@example.com');

        $response = $this->postJson($this->adminUserUri('batchBan'), [
            'user_ids' => [$userWithIp->id, $userWithoutIp->id],
            'reason' => 'fraud batch',
        ], $this->adminHeaders($admin));

        $response->assertOk()
            ->assertJsonPath('data.bannedUserCount', 2)
            ->assertJsonPath('data.blockedIpCount', 1)
            ->assertJsonPath('data.blockedIps.0', '203.0.113.30')
            ->assertJsonPath('data.skippedIpUserIds.0', $userWithoutIp->id);

        $this->assertDatabaseHas('v2_user', [
            'id' => $userWithIp->id,
            'banned' => 1,
        ]);
        $this->assertDatabaseHas('blocked_user_ips', [
            'ip' => '203.0.113.30',
            'banned_user_id' => $userWithIp->id,
            'operator_user_id' => $admin->id,
            'reason' => 'fraud batch',
        ]);
    }

    public function test_admin_user_fetch_can_filter_only_banned_users(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);
        $bannedUser = $this->createUser('banned@example.com', ['banned' => 1]);
        $this->createUser('normal@example.com', ['banned' => 0]);

        $response = $this->postJson($this->adminUserUri('fetch'), [
            'onlyBanned' => true,
            'pageSize' => 10,
        ], $this->adminHeaders($admin));

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $bannedUser->id);
    }

    public function test_admin_user_fetch_can_filter_by_registration_date_range(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);
        $insideFirst = $this->createUser('inside-first@example.com', [
            'created_at' => strtotime('2026-06-10 10:00:00'),
        ]);
        $insideSecond = $this->createUser('inside-second@example.com', [
            'created_at' => strtotime('2026-06-12 23:59:59'),
        ]);
        $this->createUser('before@example.com', [
            'created_at' => strtotime('2026-06-09 23:59:59'),
        ]);
        $this->createUser('after@example.com', [
            'created_at' => strtotime('2026-06-13 00:00:00'),
        ]);

        $response = $this->postJson($this->adminUserUri('fetch'), [
            'createdAtFrom' => '2026-06-10',
            'createdAtTo' => '2026-06-12',
            'pageSize' => 20,
        ], $this->adminHeaders($admin));

        $response->assertOk()
            ->assertJsonPath('data.total', 2);

        $ids = collect($response->json('data.data'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$insideFirst->id, $insideSecond->id], $ids);
    }

    public function test_admin_can_fetch_blocked_ip_records(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);
        $bannedUser = $this->createUser('banned@example.com');

        $targetRecord = BlockedUserIp::create([
            'ip' => '203.0.113.50',
            'banned_user_id' => $bannedUser->id,
            'operator_user_id' => $admin->id,
            'reason' => 'manual review',
            'metadata' => ['source' => 'admin_batch_ban'],
        ]);
        BlockedUserIp::create([
            'ip' => '203.0.113.51',
            'reason' => 'other record',
        ]);

        $response = $this->postJson($this->adminUserUri('blockedIp/fetch'), [
            'ip' => '203.0.113.50',
            'pageSize' => 10,
        ], $this->adminHeaders($admin));

        $response->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $targetRecord->id)
            ->assertJsonPath('data.data.0.ip', '203.0.113.50')
            ->assertJsonPath('data.data.0.banned_user.id', $bannedUser->id)
            ->assertJsonPath('data.data.0.operator_user.id', $admin->id);
    }

    public function test_admin_can_delete_blocked_ip_record(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);
        $record = BlockedUserIp::create([
            'ip' => '203.0.113.60',
            'reason' => 'temporary block',
        ]);

        $this->postJson($this->adminUserUri('blockedIp/delete'), [
            'id' => $record->id,
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data', true);

        $this->assertDatabaseMissing('blocked_user_ips', [
            'id' => $record->id,
            'ip' => '203.0.113.60',
        ]);
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
