<?php

namespace Tests\Feature;

use App\Models\BlockedUserIp;
use App\Models\AidChannelTypeUpdateQueue;
use App\Models\AidLoginBanRule;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\ProjectUserAppMap;
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
        ])->assertOk()
            ->assertJsonPath('data.is_ban', false);

        $user = User::query()->where('email', 'device-001@apple.com')->firstOrFail();

        $this->assertSame('203.0.113.10', $user->register_metadata['ip'] ?? null);
        $this->assertFalse((bool) $user->banned);
    }

    public function test_v3_login_by_aid_returns_login_data_when_ip_is_blocked(): void
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
        ])->assertOk()
            ->assertJsonPath('data.is_ban', true)
            ->assertJsonStructure(['data' => ['auth_data', 'token', 'is_ban']]);

        $user = User::query()->where('email', 'device-002@apple.com')->firstOrFail();

        $this->assertTrue((bool) $user->banned);
        $this->assertSame('203.0.113.20', $user->register_metadata['ip'] ?? null);
    }

    public function test_v1_login_by_aid_still_rejects_when_ip_is_blocked(): void
    {
        BlockedUserIp::create([
            'ip' => '203.0.113.21',
            'reason' => 'known abusive IP',
        ]);

        $this->postJson('/api/v1/passport/auth/loginByAid', [
            'aid' => 'device-v1-blocked-ip',
            'metadata' => [
                'app_id' => 'com.example.app',
                'ip' => '203.0.113.21',
            ],
        ])->assertStatus(400);

        $user = User::query()->where('email', 'device-v1-blocked-ip@apple.com')->firstOrFail();
        $this->assertTrue((bool) $user->banned);
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
            'type' => BlockedUserIp::TYPE_NORMAL,
            'banned_user_id' => $userWithIp->id,
            'operator_user_id' => $admin->id,
            'reason' => 'fraud batch',
        ]);
    }

    public function test_admin_batch_ban_can_mark_registration_ip_as_dangerous(): void
    {
        $admin = $this->createUser('admin-danger@example.com', ['is_admin' => 1]);
        $userWithIp = $this->createUser('danger-ip@example.com', [
            'register_metadata' => ['ip' => '203.0.113.31', 'app_id' => 'com.example.app'],
        ]);

        $this->postJson($this->adminUserUri('batchBan'), [
            'user_ids' => [$userWithIp->id],
            'reason' => 'danger batch',
            'type' => BlockedUserIp::TYPE_DANGEROUS,
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.bannedUserCount', 1)
            ->assertJsonPath('data.blockedIpCount', 1);

        $this->assertDatabaseHas('blocked_user_ips', [
            'ip' => '203.0.113.31',
            'type' => BlockedUserIp::TYPE_DANGEROUS,
            'banned_user_id' => $userWithIp->id,
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

    public function test_admin_user_fetch_can_filter_by_country_and_ip_metadata(): void
    {
        $admin = $this->createUser('admin-country-ip@example.com', ['is_admin' => 1]);
        $matched = $this->createUser('country-ip-match@example.com', [
            'register_metadata' => [
                'app_id' => 'com.example.app',
                'country' => 'US',
                'ip' => '203.0.113.90',
            ],
        ]);
        $this->createUser('country-ip-other-country@example.com', [
            'register_metadata' => [
                'app_id' => 'com.example.app',
                'country' => 'CA',
                'ip' => '203.0.113.90',
            ],
        ]);
        $this->createUser('country-ip-other-ip@example.com', [
            'register_metadata' => [
                'app_id' => 'com.example.app',
                'country' => 'US',
                'ip' => '203.0.113.91',
            ],
        ]);

        $this->postJson($this->adminUserUri('fetch'), [
            'country' => 'us',
            'ip' => '203.0.113.90',
            'pageSize' => 10,
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $matched->id);
    }

    public function test_admin_user_fetch_can_filter_metadata_fields_from_table_filters(): void
    {
        $admin = $this->createUser('admin-filter-metadata@example.com', ['is_admin' => 1]);
        $matched = $this->createUser('filter-metadata-match@example.com', [
            'register_metadata' => [
                'app_id' => 'com.example.app',
                'country' => 'US',
                'ip' => '203.0.113.92',
            ],
        ]);
        $this->createUser('filter-metadata-other@example.com', [
            'register_metadata' => [
                'app_id' => 'com.example.other',
                'country' => 'US',
                'ip' => '203.0.113.92',
            ],
        ]);

        $this->postJson($this->adminUserUri('fetch'), [
            'filter' => [
                ['id' => 'app_id', 'value' => 'com.example.app'],
                ['id' => 'country', 'value' => 'us'],
                ['id' => 'ip', 'value' => '203.0.113.92'],
            ],
            'pageSize' => 10,
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $matched->id);
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
            ->assertJsonPath('data.data.0.type', BlockedUserIp::TYPE_NORMAL)
            ->assertJsonPath('data.data.0.banned_user.id', $bannedUser->id)
            ->assertJsonPath('data.data.0.operator_user.id', $admin->id);
    }

    public function test_admin_can_filter_blocked_ip_records_by_type(): void
    {
        $admin = $this->createUser('admin-filter-type@example.com', ['is_admin' => 1]);

        $dangerousRecord = BlockedUserIp::create([
            'ip' => '203.0.113.52',
            'type' => BlockedUserIp::TYPE_DANGEROUS,
            'reason' => 'dangerous record',
        ]);
        BlockedUserIp::create([
            'ip' => '203.0.113.53',
            'type' => BlockedUserIp::TYPE_NORMAL,
            'reason' => 'normal record',
        ]);

        $this->postJson($this->adminUserUri('blockedIp/fetch'), [
            'type' => BlockedUserIp::TYPE_DANGEROUS,
            'pageSize' => 10,
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $dangerousRecord->id)
            ->assertJsonPath('data.data.0.type', BlockedUserIp::TYPE_DANGEROUS);
    }

    public function test_banned_user_is_unbanned_after_using_trusted_invite_code(): void
    {
        $inviter = $this->createUser('trusted-inviter@example.com', [
            'register_metadata' => ['ip' => '203.0.113.80'],
        ]);
        $invitee = $this->createUser('trusted-invitee@example.com', [
            'banned' => 1,
            'register_metadata' => ['ip' => '203.0.113.81'],
        ]);
        $this->createReusableInviteCode($inviter, 'TRUST1');
        BlockedUserIp::create([
            'ip' => '203.0.113.81',
            'type' => BlockedUserIp::TYPE_NORMAL,
            'banned_user_id' => $invitee->id,
            'reason' => 'normal risk',
        ]);

        $this->postJson('/api/v3/user/invite-codes/use', [
            'inviteCode' => 'TRUST1',
        ], $this->userHeaders($invitee))->assertOk()
            ->assertJsonPath('data.bound', true)
            ->assertJsonPath('data.inviterUserId', $inviter->id)
            ->assertJsonPath('data.unbanned', true);

        $this->assertFalse((bool) $invitee->refresh()->banned);
    }

    public function test_banned_user_is_not_unbanned_when_invitee_ip_is_dangerous(): void
    {
        $inviter = $this->createUser('safe-inviter@example.com', [
            'register_metadata' => ['ip' => '203.0.113.82'],
        ]);
        $invitee = $this->createUser('danger-invitee@example.com', [
            'banned' => 1,
            'register_metadata' => ['ip' => '203.0.113.83'],
        ]);
        $this->createReusableInviteCode($inviter, 'DANGER1');
        BlockedUserIp::create([
            'ip' => '203.0.113.83',
            'type' => BlockedUserIp::TYPE_DANGEROUS,
            'banned_user_id' => $invitee->id,
            'reason' => 'dangerous invitee',
        ]);

        $this->postJson('/api/v3/user/invite-codes/use', [
            'inviteCode' => 'DANGER1',
        ], $this->userHeaders($invitee))->assertOk()
            ->assertJsonPath('data.bound', true)
            ->assertJsonPath('data.unbanned', false);

        $this->assertTrue((bool) $invitee->refresh()->banned);
    }

    public function test_banned_user_is_not_unbanned_when_inviter_ip_is_dangerous(): void
    {
        $inviter = $this->createUser('danger-inviter@example.com', [
            'register_metadata' => ['ip' => '203.0.113.84'],
        ]);
        $invitee = $this->createUser('normal-invitee@example.com', [
            'banned' => 1,
            'register_metadata' => ['ip' => '203.0.113.85'],
        ]);
        $this->createReusableInviteCode($inviter, 'DANGER2');
        BlockedUserIp::create([
            'ip' => '203.0.113.84',
            'type' => BlockedUserIp::TYPE_DANGEROUS,
            'banned_user_id' => $inviter->id,
            'reason' => 'dangerous inviter',
        ]);

        $this->postJson('/api/v3/user/invite-codes/use', [
            'inviteCode' => 'DANGER2',
        ], $this->userHeaders($invitee))->assertOk()
            ->assertJsonPath('data.bound', true)
            ->assertJsonPath('data.unbanned', false);

        $this->assertTrue((bool) $invitee->refresh()->banned);
    }

    public function test_unbanned_user_using_invite_code_returns_unbanned_false(): void
    {
        $inviter = $this->createUser('already-ok-inviter@example.com');
        $invitee = $this->createUser('already-ok-invitee@example.com', [
            'banned' => 0,
        ]);
        $this->createReusableInviteCode($inviter, 'NORMAL1');

        $this->postJson('/api/v3/user/invite-codes/use', [
            'inviteCode' => 'NORMAL1',
        ], $this->userHeaders($invitee))->assertOk()
            ->assertJsonPath('data.bound', true)
            ->assertJsonPath('data.unbanned', false);

        $this->assertFalse((bool) $invitee->refresh()->banned);
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

    public function test_admin_can_batch_delete_blocked_ip_records(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);
        $first = BlockedUserIp::create([
            'ip' => '203.0.113.61',
            'reason' => 'temporary block',
        ]);
        $second = BlockedUserIp::create([
            'ip' => '203.0.113.62',
            'reason' => 'temporary block',
        ]);
        $kept = BlockedUserIp::create([
            'ip' => '203.0.113.63',
            'reason' => 'temporary block',
        ]);
        $missingId = $kept->id + 1000;

        $this->postJson($this->adminUserUri('blockedIp/batchDelete'), [
            'ids' => [$first->id, $second->id, $second->id, $missingId],
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.deletedCount', 2)
            ->assertJsonPath('data.requestedCount', 3)
            ->assertJsonPath('data.missingIds.0', $missingId);

        $this->assertDatabaseMissing('blocked_user_ips', [
            'id' => $first->id,
            'ip' => '203.0.113.61',
        ]);
        $this->assertDatabaseMissing('blocked_user_ips', [
            'id' => $second->id,
            'ip' => '203.0.113.62',
        ]);
        $this->assertDatabaseHas('blocked_user_ips', [
            'id' => $kept->id,
            'ip' => '203.0.113.63',
        ]);
    }

    public function test_admin_batch_delete_blocked_ip_validates_ids(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);

        $this->postJson($this->adminUserUri('blockedIp/batchDelete'), [
            'ids' => [],
        ], $this->adminHeaders($admin))->assertStatus(422);

        $this->postJson($this->adminUserUri('blockedIp/batchDelete'), [
            'ids' => [0, -1, 'invalid'],
        ], $this->adminHeaders($admin))->assertStatus(422);
    }

    public function test_admin_can_update_blocked_ip_type(): void
    {
        $admin = $this->createUser('admin-update-type@example.com', ['is_admin' => 1]);
        $record = BlockedUserIp::create([
            'ip' => '203.0.113.64',
            'type' => BlockedUserIp::TYPE_NORMAL,
            'reason' => 'manual review',
        ]);

        $this->postJson($this->adminUserUri('blockedIp/updateType'), [
            'id' => $record->id,
            'type' => BlockedUserIp::TYPE_DANGEROUS,
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.id', $record->id)
            ->assertJsonPath('data.ip', '203.0.113.64')
            ->assertJsonPath('data.type', BlockedUserIp::TYPE_DANGEROUS);

        $this->assertDatabaseHas('blocked_user_ips', [
            'id' => $record->id,
            'type' => BlockedUserIp::TYPE_DANGEROUS,
        ]);
    }

    public function test_admin_update_blocked_ip_type_validates_type_and_missing_record(): void
    {
        $admin = $this->createUser('admin-update-type-invalid@example.com', ['is_admin' => 1]);

        $this->postJson($this->adminUserUri('blockedIp/updateType'), [
            'id' => 1,
            'type' => 'invalid',
        ], $this->adminHeaders($admin))->assertStatus(422);

        $this->postJson($this->adminUserUri('blockedIp/updateType'), [
            'id' => 999999,
            'type' => BlockedUserIp::TYPE_DANGEROUS,
        ], $this->adminHeaders($admin))->assertStatus(400);
    }

    public function test_login_by_aid_bans_new_user_when_custom_rule_matches(): void
    {
        $window = $this->currentHourWindow();
        $rule = AidLoginBanRule::create([
            'name' => 'US full day block',
            'enabled' => true,
            'timezone' => 'Asia/Shanghai',
            'cutoff_at' => now()->addDay()->timestamp,
            'weekly_windows' => [
                [
                    'weekday' => (int) now()->isoWeekday(),
                    'start' => $window['start'],
                    'end' => $window['end'],
                ],
            ],
            'package_names' => ['com.example.vpn'],
            'countries' => ['US'],
            'reason' => 'custom fraud rule',
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'device-rule-001',
            'metadata' => [
                'app_id' => 'fallback.app',
                'packageName' => 'com.example.vpn',
                'country' => 'us',
                'ip' => '203.0.113.70',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', true)
            ->assertJsonStructure(['data' => ['auth_data', 'token', 'is_ban']]);

        $user = User::query()->where('email', 'device-rule-001@apple.com')->firstOrFail();
        $this->assertTrue((bool) $user->banned);
        $this->assertSame('US', $user->register_metadata['country'] ?? null);
        $this->assertSame('com.example.vpn', $user->register_metadata['package_name'] ?? null);

        $blockedIp = BlockedUserIp::query()->where('ip', '203.0.113.70')->firstOrFail();
        $this->assertSame($user->id, $blockedIp->banned_user_id);
        $this->assertSame('custom fraud rule', $blockedIp->reason);
        $this->assertSame('aid_login_ban_rule', $blockedIp->metadata['source'] ?? null);
        $this->assertSame($rule->id, $blockedIp->metadata['rule_id'] ?? null);
    }

    public function test_custom_rule_requires_package_and_country_to_be_contained(): void
    {
        $window = $this->currentHourWindow();
        AidLoginBanRule::create([
            'name' => 'Package and country required',
            'enabled' => true,
            'timezone' => 'Asia/Shanghai',
            'cutoff_at' => now()->addDay()->timestamp,
            'weekly_windows' => [[
                'weekday' => (int) now()->isoWeekday(),
                'start' => $window['start'],
                'end' => $window['end'],
            ]],
            'package_names' => ['com.example.vpn'],
            'countries' => ['US'],
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'device-rule-package-miss',
            'metadata' => [
                'app_id' => 'other.app',
                'packageName' => 'other.app',
                'country' => 'US',
                'ip' => '203.0.113.73',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', false);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'device-rule-country-miss',
            'metadata' => [
                'app_id' => 'com.example.vpn',
                'country' => 'CA',
                'ip' => '203.0.113.74',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', false);

        $this->assertDatabaseMissing('blocked_user_ips', [
            'ip' => '203.0.113.73',
        ]);
        $this->assertDatabaseMissing('blocked_user_ips', [
            'ip' => '203.0.113.74',
        ]);
    }

    public function test_custom_rule_matches_specific_date_window(): void
    {
        $window = $this->currentHourWindow();
        AidLoginBanRule::create([
            'name' => 'Specific date rule',
            'enabled' => true,
            'timezone' => 'Asia/Shanghai',
            'cutoff_at' => now()->addDay()->timestamp,
            'date_windows' => [[
                'date' => now('Asia/Shanghai')->format('Y-m-d'),
                'start' => $window['start'],
                'end' => $window['end'],
            ]],
            'package_names' => ['com.example.date'],
            'countries' => null,
            'reason' => 'date window rule',
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'device-rule-date-window',
            'metadata' => [
                'app_id' => 'com.example.date',
                'ip' => '203.0.113.77',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', true);

        $user = User::query()->where('email', 'device-rule-date-window@apple.com')->firstOrFail();
        $this->assertTrue((bool) $user->banned);
        $this->assertDatabaseHas('blocked_user_ips', [
            'ip' => '203.0.113.77',
            'banned_user_id' => $user->id,
        ]);
    }

    public function test_custom_rule_does_not_match_outside_specific_date_window(): void
    {
        $window = $this->currentHourWindow();
        AidLoginBanRule::create([
            'name' => 'Future date rule',
            'enabled' => true,
            'timezone' => 'Asia/Shanghai',
            'cutoff_at' => now()->addDay()->timestamp,
            'date_windows' => [[
                'date' => now('Asia/Shanghai')->addDay()->format('Y-m-d'),
                'start' => $window['start'],
                'end' => $window['end'],
            ]],
            'package_names' => ['com.example.future-date'],
            'countries' => null,
            'reason' => 'future date rule',
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'device-rule-future-date',
            'metadata' => [
                'app_id' => 'com.example.future-date',
                'ip' => '203.0.113.78',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', false);

        $user = User::query()->where('email', 'device-rule-future-date@apple.com')->firstOrFail();
        $this->assertFalse((bool) $user->banned);
        $this->assertDatabaseMissing('blocked_user_ips', [
            'ip' => '203.0.113.78',
        ]);
    }

    public function test_custom_rule_does_not_match_without_package_names(): void
    {
        AidLoginBanRule::create([
            'name' => 'No package rule',
            'enabled' => true,
            'timezone' => 'Asia/Shanghai',
            'cutoff_at' => null,
            'weekly_windows' => null,
            'package_names' => null,
            'countries' => null,
            'reason' => 'no package rule',
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'device-rule-no-package',
            'metadata' => [
                'app_id' => 'any.app',
                'country' => 'CA',
                'ip' => '203.0.113.75',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', false);

        $user = User::query()->where('email', 'device-rule-no-package@apple.com')->firstOrFail();
        $this->assertFalse((bool) $user->banned);

        $this->assertDatabaseMissing('blocked_user_ips', [
            'ip' => '203.0.113.75',
        ]);
    }

    public function test_login_by_aid_does_not_apply_custom_rule_to_existing_user(): void
    {
        AidLoginBanRule::create([
            'name' => 'Existing user should not be affected',
            'enabled' => true,
            'timezone' => 'Asia/Shanghai',
            'cutoff_at' => now()->addDay()->timestamp,
            'weekly_windows' => [[
                'weekday' => (int) now()->isoWeekday(),
                'start' => '00:00',
                'end' => '23:59',
            ]],
            'package_names' => ['com.example.vpn'],
            'countries' => ['US'],
            'reason' => 'custom fraud rule',
        ]);
        $user = $this->createAidUser('existing-aid');

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'existing-aid',
            'metadata' => [
                'app_id' => 'com.example.vpn',
                'country' => 'US',
                'ip' => '203.0.113.71',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', false);

        $this->assertFalse((bool) $user->refresh()->banned);
        $this->assertDatabaseMissing('blocked_user_ips', [
            'ip' => '203.0.113.71',
        ]);
    }

    public function test_existing_aid_user_with_unknown_channel_type_queues_channel_type_update(): void
    {
        $user = $this->createAidUser('existing-unknown-channel', [
            'register_metadata' => [
                'channel_type' => 'unknown',
                'utm_source' => 'old-source',
            ],
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'existing-unknown-channel',
            'metadata' => [
                'app_id' => 'com.example.app',
            ],
            'channel' => [
                'channel_type' => 'paid',
                'utm_source' => 'new-source',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', false);

        $this->assertSame('unknown', $user->refresh()->register_metadata['channel_type'] ?? null);
        $this->assertSame('old-source', $user->register_metadata['utm_source'] ?? null);

        $this->assertDatabaseHas('aid_channel_type_update_queues', [
            'user_id' => $user->id,
            'channel_type' => 'PAID',
        ]);
    }

    public function test_existing_aid_user_channel_type_queue_uses_v1_flat_channel_type(): void
    {
        $user = $this->createAidUser('existing-v1-unknown-channel', [
            'register_metadata' => [
                'channel_type' => 'UNKNOWN',
            ],
        ]);

        $this->postJson('/api/v1/passport/auth/loginByAid', [
            'aid' => 'existing-v1-unknown-channel',
            'metadata' => [
                'app_id' => 'com.example.app',
                'channel_type' => 'organic',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('aid_channel_type_update_queues', [
            'user_id' => $user->id,
            'channel_type' => 'ORGANIC',
        ]);
    }

    public function test_existing_aid_user_channel_type_queue_ignores_non_unknown_or_missing_values(): void
    {
        $knownUser = $this->createAidUser('existing-known-channel', [
            'register_metadata' => [
                'channel_type' => 'PAID',
            ],
        ]);
        $missingUser = $this->createAidUser('existing-missing-channel', [
            'register_metadata' => [
                'app_id' => 'com.example.app',
            ],
        ]);
        $stillUnknownUser = $this->createAidUser('existing-still-unknown-channel', [
            'register_metadata' => [
                'channel_type' => 'UNKNOWN',
            ],
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'existing-known-channel',
            'metadata' => [
                'app_id' => 'com.example.app',
            ],
            'channel' => [
                'channel_type' => 'organic',
            ],
        ])->assertOk();

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'existing-missing-channel',
            'metadata' => [
                'app_id' => 'com.example.app',
            ],
            'channel' => [
                'channel_type' => 'paid',
            ],
        ])->assertOk();

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'existing-still-unknown-channel',
            'metadata' => [
                'app_id' => 'com.example.app',
            ],
            'channel' => [
                'channel_type' => 'unknown',
            ],
        ])->assertOk();

        $this->assertDatabaseMissing('aid_channel_type_update_queues', [
            'user_id' => $knownUser->id,
        ]);
        $this->assertDatabaseMissing('aid_channel_type_update_queues', [
            'user_id' => $missingUser->id,
        ]);
        $this->assertDatabaseMissing('aid_channel_type_update_queues', [
            'user_id' => $stillUnknownUser->id,
        ]);
    }

    public function test_aid_channel_type_flush_updates_only_channel_type(): void
    {
        $user = $this->createAidUser('flush-channel-type', [
            'last_login_at' => 100,
            'register_metadata' => [
                'channel_type' => 'UNKNOWN',
                'utm_source' => 'old-source',
                'raw_referrer' => 'old-referrer',
            ],
        ]);

        AidChannelTypeUpdateQueue::create([
            'user_id' => $user->id,
            'channel_type' => 'ORGANIC',
            'last_login_at' => 200,
        ]);

        $this->artisan('aid-channel-type:flush')
            ->expectsOutput('AID channel_type updates scanned=1 updated=1 failed=0')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertSame('ORGANIC', $user->register_metadata['channel_type'] ?? null);
        $this->assertSame('old-source', $user->register_metadata['utm_source'] ?? null);
        $this->assertSame('old-referrer', $user->register_metadata['raw_referrer'] ?? null);
        $this->assertSame(200, (int) $user->last_login_at);
        $this->assertDatabaseMissing('aid_channel_type_update_queues', [
            'user_id' => $user->id,
        ]);
    }

    public function test_custom_rule_does_not_match_after_cutoff(): void
    {
        AidLoginBanRule::create([
            'name' => 'Expired rule',
            'enabled' => true,
            'timezone' => 'Asia/Shanghai',
            'cutoff_at' => now()->subMinute()->timestamp,
            'weekly_windows' => [[
                'weekday' => (int) now()->isoWeekday(),
                'start' => '00:00',
                'end' => '23:59',
            ]],
            'package_names' => ['com.example.vpn'],
            'countries' => ['US'],
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'device-rule-expired',
            'metadata' => [
                'app_id' => 'com.example.vpn',
                'country' => 'US',
                'ip' => '203.0.113.72',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', false);

        $user = User::query()->where('email', 'device-rule-expired@apple.com')->firstOrFail();
        $this->assertFalse((bool) $user->banned);
    }

    public function test_admin_can_manage_aid_login_ban_rules(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);

        $saveResponse = $this->postJson($this->adminUserUri('aidLoginBanRule/save'), [
            'name' => 'Admin managed rule',
            'enabled' => true,
            'timezone' => 'Asia/Shanghai',
            'cutoffAt' => now()->addDay()->format('Y-m-d H:i:s'),
            'weeklyWindows' => [[
                'weekday' => 1,
                'start' => '00:00',
                'end' => '06:00',
            ]],
            'packageNames' => ['com.example.vpn'],
            'countries' => ['us'],
            'reason' => 'admin rule',
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.name', 'Admin managed rule')
            ->assertJsonPath('data.timezone', 'Asia/Shanghai')
            ->assertJsonPath('data.dateWindows', [])
            ->assertJsonPath('data.countries.0', 'US');

        $ruleId = (int) $saveResponse->json('data.id');

        $this->postJson($this->adminUserUri('aidLoginBanRule/fetch'), [
            'packageName' => 'com.example.vpn',
            'country' => 'US',
            'pageSize' => 10,
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $ruleId);

        $this->postJson($this->adminUserUri('aidLoginBanRule/update'), [
            'id' => $ruleId,
            'enabled' => false,
            'cutoffAt' => null,
            'weeklyWindows' => null,
            'reason' => 'disabled',
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.cutoffAt', null)
            ->assertJsonPath('data.weeklyWindows', [])
            ->assertJsonPath('data.reason', 'disabled');

        $this->postJson($this->adminUserUri('aidLoginBanRule/delete'), [
            'id' => $ruleId,
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data', true);

        $this->assertDatabaseMissing('aid_login_ban_rules', [
            'id' => $ruleId,
        ]);
    }

    public function test_admin_can_save_aid_login_ban_rule_without_conditions(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);

        $this->postJson($this->adminUserUri('aidLoginBanRule/save'), [
            'name' => 'No condition admin rule',
            'timezone' => 'Asia/Shanghai',
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.name', 'No condition admin rule')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.timezone', 'Asia/Shanghai')
            ->assertJsonPath('data.cutoffAt', null)
            ->assertJsonPath('data.weeklyWindows', [])
            ->assertJsonPath('data.dateWindows', [])
            ->assertJsonPath('data.packageNames', [])
            ->assertJsonPath('data.projectCodes', [])
            ->assertJsonPath('data.countries', []);
    }

    public function test_admin_save_aid_login_ban_rule_resolves_project_codes_to_package_names(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);

        ProjectUserAppMap::create([
            'project_code' => 'rocket',
            'app_id' => 'com.rocket.vpn',
            'enabled' => 1,
        ]);
        ProjectUserAppMap::create([
            'project_code' => 'rocket',
            'app_id' => 'com.rocket.disabled',
            'enabled' => 0,
        ]);
        ProjectUserAppMap::create([
            'project_code' => 'other',
            'app_id' => 'com.other.vpn',
            'enabled' => 1,
        ]);

        $response = $this->postJson($this->adminUserUri('aidLoginBanRule/save'), [
            'name' => 'Project code rule',
            'timezone' => 'Asia/Shanghai',
            'packageNames' => ['com.manual.vpn'],
            'projectCodes' => ['rocket'],
            'reason' => 'project code match',
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.projectCodes.0', 'rocket')
            ->assertJsonPath('data.packageNames.0', 'com.manual.vpn')
            ->assertJsonPath('data.packageNames.1', 'com.rocket.vpn');

        $this->assertDatabaseHas('aid_login_ban_rules', [
            'id' => (int) $response->json('data.id'),
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'device-project-code',
            'metadata' => [
                'app_id' => 'com.rocket.vpn',
                'ip' => '203.0.113.76',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', true);

        $user = User::query()->where('email', 'device-project-code@apple.com')->firstOrFail();
        $this->assertTrue((bool) $user->banned);

        $blockedIp = BlockedUserIp::query()->where('ip', '203.0.113.76')->firstOrFail();
        $this->assertSame('aid_login_ban_rule', $blockedIp->metadata['source'] ?? null);
        $this->assertSame('com.rocket.vpn', $blockedIp->metadata['package_name'] ?? null);
    }

    public function test_v3_login_by_aid_returns_login_data_for_existing_banned_aid_user(): void
    {
        $user = $this->createAidUser('existing-banned-aid', [
            'banned' => 1,
        ]);

        $this->postJson('/api/v3/passport/auth/loginByAid', [
            'aid' => 'existing-banned-aid',
            'metadata' => [
                'app_id' => 'com.example.app',
            ],
        ])->assertOk()
            ->assertJsonPath('data.is_ban', true)
            ->assertJsonStructure(['data' => ['auth_data', 'token', 'is_ban']]);

        $this->assertTrue((bool) $user->refresh()->banned);
    }

    public function test_v1_login_by_aid_still_rejects_existing_banned_aid_user(): void
    {
        $this->createAidUser('existing-v1-banned-aid', [
            'banned' => 1,
        ]);

        $this->postJson('/api/v1/passport/auth/loginByAid', [
            'aid' => 'existing-v1-banned-aid',
            'metadata' => [
                'app_id' => 'com.example.app',
            ],
        ])->assertStatus(400);
    }

    public function test_password_login_still_rejects_banned_user(): void
    {
        $this->createUser('password-banned@example.com', [
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'banned' => 1,
        ]);

        $this->postJson('/api/v3/passport/auth/login', [
            'email' => 'password-banned@example.com',
            'password' => 'password123',
        ])->assertStatus(400);
    }

    public function test_admin_update_project_codes_rebuilds_resolved_package_names(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);

        ProjectUserAppMap::create([
            'project_code' => 'old',
            'app_id' => 'com.old.vpn',
            'enabled' => 1,
        ]);
        ProjectUserAppMap::create([
            'project_code' => 'new',
            'app_id' => 'com.new.vpn',
            'enabled' => 1,
        ]);

        $saveResponse = $this->postJson($this->adminUserUri('aidLoginBanRule/save'), [
            'name' => 'Project code update rule',
            'timezone' => 'Asia/Shanghai',
            'packageNames' => ['com.manual.vpn'],
            'projectCodes' => ['old'],
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.packageNames.0', 'com.manual.vpn')
            ->assertJsonPath('data.packageNames.1', 'com.old.vpn');

        $ruleId = (int) $saveResponse->json('data.id');

        $this->postJson($this->adminUserUri('aidLoginBanRule/update'), [
            'id' => $ruleId,
            'projectCodes' => ['new'],
        ], $this->adminHeaders($admin))->assertOk()
            ->assertJsonPath('data.projectCodes.0', 'new')
            ->assertJsonPath('data.packageNames.0', 'com.manual.vpn')
            ->assertJsonPath('data.packageNames.1', 'com.new.vpn');

        $rule = AidLoginBanRule::query()->findOrFail($ruleId);
        $this->assertSame(['new'], $rule->project_codes);
        $this->assertSame(['com.manual.vpn', 'com.new.vpn'], $rule->package_names);
    }

    public function test_admin_can_fetch_project_code_package_name_mappings(): void
    {
        $admin = $this->createUser('admin@example.com', ['is_admin' => 1]);

        ProjectUserAppMap::create([
            'project_code' => 'rocket',
            'app_id' => 'com.rocket.a',
            'enabled' => 1,
        ]);
        ProjectUserAppMap::create([
            'project_code' => 'rocket',
            'app_id' => 'com.rocket.b',
            'enabled' => 1,
        ]);
        ProjectUserAppMap::create([
            'project_code' => 'rocket',
            'app_id' => 'com.rocket.disabled',
            'enabled' => 0,
        ]);
        ProjectUserAppMap::create([
            'project_code' => 'space',
            'app_id' => 'com.space.app',
            'enabled' => 1,
        ]);

        $this->getJson($this->adminProjectUri('user-apps/mappings'), $this->adminHeaders($admin))
            ->assertOk()
            ->assertJsonPath('data.0.projectCode', 'rocket')
            ->assertJsonPath('data.0.packageNames.0', 'com.rocket.a')
            ->assertJsonPath('data.0.packageNames.1', 'com.rocket.b')
            ->assertJsonPath('data.0.appCount', 2)
            ->assertJsonPath('data.1.projectCode', 'space');

        $this->getJson($this->adminProjectUri('user-apps/mappings') . '?includeDisabled=1&projectCode=rocket', $this->adminHeaders($admin))
            ->assertOk()
            ->assertJsonPath('data.0.projectCode', 'rocket')
            ->assertJsonPath('data.0.appCount', 3)
            ->assertJsonPath('data.0.packageNames.2', 'com.rocket.disabled');
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

    private function createAidUser(string $aid, array $overrides = []): User
    {
        return $this->createUser($aid . '@apple.com', array_replace([
            'password' => password_hash($aid, PASSWORD_DEFAULT),
            'password_algo' => null,
            'password_salt' => null,
        ], $overrides));
    }

    private function currentHourWindow(): array
    {
        return [
            'start' => now()->startOfHour()->format('H:i'),
            'end' => now()->endOfHour()->format('H:i'),
        ];
    }

    private function adminHeaders(User $admin): array
    {
        return [
            'Authorization' => (new AuthService($admin))->generateAuthData()['auth_data'],
        ];
    }

    private function userHeaders(User $user): array
    {
        return [
            'Authorization' => (new AuthService($user))->generateAuthData()['auth_data'],
        ];
    }

    private function createReusableInviteCode(User $user, string $code): InviteCode
    {
        $inviteCode = new InviteCode();
        $inviteCode->user_id = $user->id;
        $inviteCode->code = 'MU-' . $code;
        $inviteCode->status = InviteCode::STATUS_UNUSED;
        $inviteCode->save();

        return $inviteCode;
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

    private function adminProjectUri(string $action): string
    {
        $suffix = 'projects/' . trim($action, '/');

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v3/') && str_ends_with($route->uri(), $suffix)) {
                return '/' . $route->uri();
            }
        }

        return '/api/v3/admin/' . $suffix;
    }
}
