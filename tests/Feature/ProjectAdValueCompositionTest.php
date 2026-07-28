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

    /**
     * Verify daily project ad-value splits same-day users, retained users, and unknown cohorts.
     */
    public function test_admin_can_query_project_daily_ad_value_composition(): void
    {
        $admin = $this->createUser('project-ad-value-daily-admin@example.com', ['is_admin' => 1]);

        $this->insertProjectAppMap('P_DAILY_VALUE', 'com.daily.a', 1);
        $this->insertProjectAppMap('P_DAILY_VALUE', 'com.daily.b', 1);
        $this->insertProjectAppMap('P_DAILY_VALUE', 'com.daily.disabled', 0);
        $this->insertProjectAppMap('P_DAILY_OTHER', 'com.daily.other', 1);

        $this->insertFirstReport(5201, 'com.daily.a', '2026-07-01');
        $this->insertFirstReport(5202, 'com.daily.a', '2026-06-30');
        $this->insertFirstReport(5204, 'com.daily.a', '2026-07-03');
        $this->insertFirstReport(5205, 'com.daily.b', '2026-06-30');
        $this->insertFirstReport(5206, 'com.daily.b', '2026-06-19');
        $this->insertFirstReport(5207, 'com.daily.a', '2026-07-04');
        $this->insertFirstReport(5298, 'com.daily.disabled', '2026-07-01');
        $this->insertFirstReport(5299, 'com.daily.other', '2026-07-01');

        $this->insertAdValue('2026-07-01', 9, 5201, 'com.daily.a', 100000000);
        $this->insertAdValue('2026-07-01', 10, 5202, 'com.daily.a', 50000000);
        $this->insertAdValue('2026-07-01', 11, 5203, 'com.daily.a', 20000000);
        $this->insertAdValue('2026-07-03', 9, 5204, 'com.daily.a', 300000000);
        $this->insertAdValue('2026-07-03', 10, 5205, 'com.daily.b', 70000000);
        $this->insertAdValue('2026-07-03', 11, 5206, 'com.daily.b', 30000000);
        $this->insertAdValue('2026-07-03', 12, 5207, 'com.daily.a', 10000000);
        $this->insertAdValue('2026-07-01', 10, 5298, 'com.daily.disabled', 999000000);
        $this->insertAdValue('2026-07-01', 10, 5299, 'com.daily.other', 999000000);

        $response = $this->postJson($this->adminReportUri('project/ad-value/daily-composition'), [
            'projectCode' => 'P_DAILY_VALUE',
            'dateFrom' => '2026-07-01',
            'dateTo' => '2026-07-03',
        ], $this->adminHeaders($admin))->assertOk();

        $data = $response->json('data');
        $rows = collect($data['data'])->keyBy('date');

        $this->assertSame('P_DAILY_VALUE', $data['projectCode']);
        $this->assertSame('2026-07-01', $data['dateFrom']);
        $this->assertSame('2026-07-03', $data['dateTo']);
        $this->assertSame(['2026-07-01', '2026-07-02', '2026-07-03'], array_keys($rows->all()));

        $this->assertSame(170000000, $rows['2026-07-01']['totalValueMicrosUsd']);
        $this->assertSame(100000000, $rows['2026-07-01']['newUserValueMicrosUsd']);
        $this->assertSame(50000000, $rows['2026-07-01']['retainedUserValueMicrosUsd']);
        $this->assertSame(20000000, $rows['2026-07-01']['unknownValueMicrosUsd']);
        $this->assertSame(0.588235, $rows['2026-07-01']['newUserRatio']);
        $this->assertSame(0.294118, $rows['2026-07-01']['retainedUserRatio']);
        $this->assertSame(0.117647, $rows['2026-07-01']['unknownRatio']);

        $this->assertSame(0, $rows['2026-07-02']['totalValueMicrosUsd']);
        $this->assertSame('0.000000', $rows['2026-07-02']['totalValueUsd']);

        $this->assertSame(410000000, $rows['2026-07-03']['totalValueMicrosUsd']);
        $this->assertSame(300000000, $rows['2026-07-03']['newUserValueMicrosUsd']);
        $this->assertSame(100000000, $rows['2026-07-03']['retainedUserValueMicrosUsd']);
        $this->assertSame(10000000, $rows['2026-07-03']['unknownValueMicrosUsd']);

        $summary = $data['summary'];
        $this->assertSame(580000000, $summary['totalValueMicrosUsd']);
        $this->assertSame('580.000000', $summary['totalValueUsd']);
        $this->assertSame(400000000, $summary['newUserValueMicrosUsd']);
        $this->assertSame(150000000, $summary['retainedUserValueMicrosUsd']);
        $this->assertSame(30000000, $summary['unknownValueMicrosUsd']);
        $this->assertSame(0.689655, $summary['newUserRatio']);
        $this->assertSame(0.258621, $summary['retainedUserRatio']);
        $this->assertSame(0.051724, $summary['unknownRatio']);
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
