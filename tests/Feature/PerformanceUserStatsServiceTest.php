<?php

namespace Tests\Feature;

use App\Services\PerformanceUserStatsService;
use App\Services\UserService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PerformanceUserStatsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Schema::dropIfExists('v3_user_report_count');
        Schema::create('v3_user_report_count', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->unsignedTinyInteger('minute');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('report_count')->default(0);
            $table->unsignedInteger('node_count')->default(0);
            $table->string('client_country', 2)->nullable();
            $table->string('client_isp', 255)->nullable();
            $table->string('platform', 100)->nullable();
            $table->string('app_id', 255)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('v3_user_report_count');

        parent::tearDown();
    }

    public function test_retention_batches_cohorts_and_keeps_target_date_filter_semantics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 12:00:00'));
        $service = $this->service();

        $this->insertReport('2026-07-01', 10, 1, ['app_id' => 'app.a', 'platform' => 'ios']);
        $this->insertReport('2026-07-01', 11, 2, ['app_id' => 'app.a', 'platform' => 'ios']);
        $this->insertReport('2026-07-02', 9, 1, ['app_id' => 'other.app', 'platform' => 'android']);
        $this->insertReport('2026-07-06', 8, 3, ['app_id' => 'app.a', 'platform' => 'ios']);

        $result = $service->retention(Request::create('/performance/retention', 'GET', [
            'dateFrom' => '2026-07-01',
            'dateTo' => '2026-07-06',
            'appId' => 'app.a',
            'platform' => 'ios',
        ]));

        $firstCohort = collect($result['data'])->firstWhere('date', '2026-07-01');
        $futureCohort = collect($result['data'])->firstWhere('date', '2026-07-06');

        $this->assertSame(2, $firstCohort['active_users']);
        $this->assertSame(['count' => 1, 'rate' => 50.0], $firstCohort['retention']['day_1']);
        $this->assertNull($futureCohort['retention']['day_3']);
    }

    public function test_active_users_counts_new_users_by_filtered_first_report_period(): void
    {
        $service = $this->service(['2026-07-01' => 7, '2026-07-02' => 8]);

        $this->insertReport('2026-06-30', 10, 3, ['app_id' => 'app.a', 'platform' => 'ios']);
        $this->insertReport('2026-07-01', 10, 1, ['app_id' => 'app.a', 'platform' => 'ios', 'report_count' => 2]);
        $this->insertReport('2026-07-02', 10, 1, ['app_id' => 'app.a', 'platform' => 'ios']);
        $this->insertReport('2026-07-02', 11, 2, ['app_id' => 'app.a', 'platform' => 'ios']);
        $this->insertReport('2026-07-02', 12, 3, ['app_id' => 'app.a', 'platform' => 'ios']);
        $this->insertReport('2026-07-02', 13, 4, ['app_id' => 'app.b', 'platform' => 'ios']);

        $result = $service->activeUsers(Request::create('/performance/activeUsers', 'GET', [
            'dateFrom' => '2026-07-01',
            'dateTo' => '2026-07-02',
            'appId' => 'app.a',
            'platform' => 'ios',
            'granularity' => 'day',
        ]));

        $rows = collect($result['data'])->keyBy(fn($row) => (string) $row->period);

        $this->assertSame(1, (int) $rows['2026-07-01']->active_users);
        $this->assertSame(1, (int) $rows['2026-07-01']->new_users);
        $this->assertSame(7, (int) $rows['2026-07-01']->reg_users);
        $this->assertSame(3, (int) $rows['2026-07-02']->active_users);
        $this->assertSame(1, (int) $rows['2026-07-02']->new_users);
    }

    public function test_user_hourly_stats_uses_cross_day_window_and_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 01:30:00'));
        $service = $this->service();

        $this->insertReport('2026-07-05', 23, 2, ['app_id' => 'app.a', 'platform' => 'ios', 'app_version' => '1.0', 'client_country' => 'US']);
        $this->insertReport('2026-07-06', 2, 1, ['app_id' => 'app.a', 'platform' => 'ios', 'app_version' => '1.0', 'client_country' => 'US']);
        $this->insertReport('2026-07-06', 2, 2, ['app_id' => 'app.a', 'platform' => 'ios', 'app_version' => '1.0', 'client_country' => 'US']);
        $this->insertReport('2026-07-06', 3, 1, ['app_id' => 'app.a', 'platform' => 'ios', 'app_version' => '1.0', 'client_country' => 'US']);
        $this->insertReport('2026-07-07', 1, 3, ['app_id' => 'app.a', 'platform' => 'ios', 'app_version' => '1.0', 'client_country' => 'US']);
        $this->insertReport('2026-07-07', 1, 4, ['app_id' => 'app.a', 'platform' => 'android', 'app_version' => '1.0', 'client_country' => 'US']);

        $result = $service->userHourlyStats(Request::create('/performance/userHourlyStats', 'GET', [
            'appId' => 'app.a',
            'platform' => 'ios',
            'appVersion' => '1.0',
            'clientCountry' => 'US',
        ]));

        $rows = collect($result['data'])->keyBy('time');

        $this->assertCount(24, $result['data']);
        $this->assertSame('2026-07-06 02:00', $result['start']);
        $this->assertSame('2026-07-07 01:00', $result['end']);
        $this->assertSame(2, $rows['2026-07-06 02:00']['active_users']);
        $this->assertSame(1, $rows['2026-07-06 02:00']['new_users']);
        $this->assertSame(1, $rows['2026-07-07 01:00']['active_users']);
        $this->assertSame(1, $rows['2026-07-07 01:00']['new_users']);
    }

    private function insertReport(string $date, int $hour, int $userId, array $overrides = []): void
    {
        DB::table('v3_user_report_count')->insert(array_replace([
            'date' => $date,
            'hour' => $hour,
            'minute' => 0,
            'user_id' => $userId,
            'report_count' => 1,
            'node_count' => 1,
            'client_country' => null,
            'client_isp' => null,
            'platform' => null,
            'app_id' => null,
            'app_version' => null,
            'created_at' => now(),
        ], $overrides));
    }

    private function service(array $registrationMap = []): PerformanceUserStatsService
    {
        $userService = new class($registrationMap) extends UserService {
            public function __construct(private readonly array $registrationMap)
            {
            }

            public function getNewUsersByDateRange(string $dateFrom, string $dateTo, string $granularity = 'day', array $filters = []): array
            {
                return $this->registrationMap;
            }
        };

        return new PerformanceUserStatsService($userService);
    }
}
