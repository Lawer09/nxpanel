<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Support\SettingStore;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProjectAdValueCompositionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingStore::class)->set('secure_path', 'admin');
    }

    /**
     * Verify project ad-value composition groups enabled project apps by all day-N cohorts.
     */
    public function test_admin_can_query_project_ad_value_composition(): void
    {
        $admin = $this->createUser('project-ad-value-admin@example.com', ['is_admin' => 1]);
        $date = '2026-07-28';

        $this->insertProjectAppMap('P_AD_VALUE', 'com.project.a', 1);
        $this->insertProjectAppMap('P_AD_VALUE', 'com.project.b', 1);
        $this->insertProjectAppMap('P_AD_VALUE', 'com.project.disabled', 0);
        $this->insertProjectAppMap('P_OTHER', 'com.project.other', 1);

        $this->insertFirstReport(5101, 'com.project.a', '2026-07-28');
        $this->insertFirstReport(5102, 'com.project.a', '2026-07-27');
        $this->insertFirstReport(5103, 'com.project.b', '2026-07-25');
        $this->insertFirstReport(5104, 'com.project.b', '2026-07-21');
        $this->insertFirstReport(5105, 'com.project.a', '2026-07-14');
        $this->insertFirstReport(5105, 'com.project.b', '2026-07-08');
        $this->insertFirstReport(5106, 'com.project.a', '2026-07-29');
        $this->insertFirstReport(5198, 'com.project.disabled', '2026-07-28');
        $this->insertFirstReport(5199, 'com.project.other', '2026-07-28');

        $this->insertAdValue($date, 9, 5101, 'com.project.a', 500000000);
        $this->insertAdValue($date, 10, 5102, 'com.project.a', 200000000);
        $this->insertAdValue($date, 11, 5103, 'com.project.b', 30000000);
        $this->insertAdValue($date, 12, 5104, 'com.project.b', 70000000);
        $this->insertAdValue($date, 13, 5105, 'com.project.a', 140000000);
        $this->insertAdValue($date, 14, 5105, 'com.project.b', 200000000);
        $this->insertAdValue($date, 15, 5106, 'com.project.a', 10000000);
        $this->insertAdValue($date, 16, 5107, 'com.project.a', 90000000);
        $this->insertAdValue($date, 10, 5198, 'com.project.disabled', 999000000);
        $this->insertAdValue($date, 10, 5199, 'com.project.other', 999000000);

        $response = $this->postJson($this->adminReportUri('project/ad-value/composition'), [
            'projectCode' => ' P_AD_VALUE ',
            'date' => $date,
        ], $this->adminHeaders($admin))->assertOk();

        $data = $response->json('data');
        $keyBuckets = collect($data['keyBuckets'])->keyBy('cohortKey');
        $buckets = collect($data['buckets'])->keyBy('cohortKey');

        $this->assertSame('P_AD_VALUE', $data['projectCode']);
        $this->assertSame($date, $data['date']);
        $this->assertSame(1240000000, $data['totalValueMicrosUsd']);
        $this->assertSame('1240.000000', $data['totalValueUsd']);

        $this->assertSame(500000000, $keyBuckets['day0']['valueMicrosUsd']);
        $this->assertSame(200000000, $keyBuckets['day1']['valueMicrosUsd']);
        $this->assertSame(30000000, $keyBuckets['day3']['valueMicrosUsd']);
        $this->assertSame(70000000, $keyBuckets['day7']['valueMicrosUsd']);
        $this->assertSame(340000000, $keyBuckets['day14_plus']['valueMicrosUsd']);
        $this->assertSame(1, $keyBuckets['day14_plus']['userCount']);
        $this->assertSame(0.274194, $keyBuckets['day14_plus']['ratio']);

        $this->assertSame(['day0', 'day1', 'day3', 'day7', 'day14', 'day20'], array_keys($buckets->all()));
        $this->assertSame(140000000, $buckets['day14']['valueMicrosUsd']);
        $this->assertSame(200000000, $buckets['day20']['valueMicrosUsd']);
        $this->assertSame(100000000, $data['unknown']['valueMicrosUsd']);
        $this->assertSame(2, $data['unknown']['userCount']);
        $this->assertSame(0.080645, $data['unknown']['ratio']);
    }

    /**
     * Verify empty project-date results still return a stable frontend contract.
     */
    public function test_admin_project_ad_value_composition_returns_zero_buckets_when_empty(): void
    {
        $admin = $this->createUser('project-ad-value-empty-admin@example.com', ['is_admin' => 1]);

        $response = $this->postJson($this->adminReportUri('project/ad-value/composition'), [
            'projectCode' => 'P_AD_VALUE_EMPTY',
            'date' => '2026-07-28',
        ], $this->adminHeaders($admin))->assertOk();

        $data = $response->json('data');
        $keyBuckets = collect($data['keyBuckets'])->keyBy('cohortKey');

        $this->assertSame(0, $data['totalValueMicrosUsd']);
        $this->assertSame('0.000000', $data['totalValueUsd']);
        $this->assertSame([], $data['buckets']);
        $this->assertSame(0, $data['unknown']['valueMicrosUsd']);
        $this->assertSame(0, $data['unknown']['userCount']);

        foreach (['day0', 'day1', 'day3', 'day7', 'day14_plus'] as $key) {
            $this->assertTrue($keyBuckets->has($key));
            $this->assertSame(0, $keyBuckets[$key]['valueMicrosUsd']);
            $this->assertSame(0, $keyBuckets[$key]['userCount']);
        }
    }

    private function insertProjectAppMap(string $projectCode, string $appId, int $enabled): void
    {
        DB::table('project_user_app_map')->insert([
            'project_code' => $projectCode,
            'app_id' => $appId,
            'enabled' => $enabled,
            'remark' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertFirstReport(int $userId, string $appId, string $date): void
    {
        DB::table('v3_user_app_first_report')->insert([
            'user_id' => $userId,
            'app_id' => $appId,
            'first_report_date' => $date,
            'first_report_hour' => 0,
            'first_report_minute' => 0,
            'first_report_at_ms' => Carbon::parse($date . ' 00:00:00', 'Asia/Shanghai')->utc()->getTimestampMs(),
            'platform' => 'ios',
            'app_version' => '1.0.0',
            'country' => 'US',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAdValue(string $date, int $hour, int $userId, string $appId, int $valueMicrosUsd): void
    {
        DB::table('v3_user_ad_value_hourly')->insert([
            'date' => $date,
            'hour' => $hour,
            'user_id' => $userId,
            'app_id' => $appId,
            'app_version' => '1.0.0',
            'platform' => 'ios',
            'country' => 'US',
            'value_micros_usd' => $valueMicrosUsd,
            'value_usd' => number_format($valueMicrosUsd / 1000000, 6, '.', ''),
            'ad_value_report_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createUser(string $email, array $overrides = []): User
    {
        return User::withoutEvents(fn(): User => User::query()->forceCreate(array_replace([
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
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides)));
    }

    private function adminHeaders(User $admin): array
    {
        return [
            'Authorization' => (new AuthService($admin))->generateAuthData()['auth_data'],
        ];
    }

    private function adminReportUri(string $action): string
    {
        $suffix = 'report/' . trim($action, '/');

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v3/') && str_ends_with($route->uri(), $suffix)) {
                return '/' . $route->uri();
            }
        }

        return '/api/v3/admin/' . $suffix;
    }
}
