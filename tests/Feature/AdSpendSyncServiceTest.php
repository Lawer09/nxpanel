<?php

namespace Tests\Feature;

use App\Http\Controllers\V3\Admin\AdSpendPlatform\AdSpendPlatformController;
use App\Models\AdSpendDailyReport;
use App\Models\AdSpendPlatformAccount;
use App\Models\AdSpendSyncJob;
use App\Models\Project;
use App\Services\AdSpendPlatformService;
use App\Services\AdSpendSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class AdSpendSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private AdSpendPlatformAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Project::create([
            'project_code' => 'A003',
            'project_name' => 'Project A003',
            'status' => Project::STATUS_ACTIVE,
        ]);

        $this->account = AdSpendPlatformAccount::create([
            'platform_code' => 'meta',
            'account_name' => 'Meta Main',
            'base_url' => 'https://ads.example.com',
            'username' => 'tester',
            'password' => 'secret',
            'enabled' => 1,
        ]);
    }

    public function test_sync_service_updates_reports_and_job_stats(): void
    {
        $service = app(AdSpendSyncService::class);
        $platformService = $this->mockPlatformService([
            $this->matchedRecord('2026-06-06', 'A003'),
            $this->unmatchedRecord('2026-06-06'),
        ]);

        $job = $service->syncAccount(
            $this->account,
            '2026-06-06',
            '2026-06-06',
            $platformService,
            AdSpendSyncService::SOURCE_SCHEDULED
        );

        $this->assertSame(AdSpendSyncJob::STATUS_SUCCESS, $job->status);
        $this->assertSame(2, $job->total_records);
        $this->assertSame(1, $job->matched_records);
        $this->assertSame(1, $job->unmatched_records);

        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-06',
            'country' => 'US',
            'platform' => '',
            'impressions' => 100,
            'clicks' => 10,
            'raw_group_name' => 'A003 campaign',
        ]);
    }

    public function test_sync_service_updates_existing_report_instead_of_creating_duplicate(): void
    {
        AdSpendDailyReport::create([
            'platform_account_id' => $this->account->id,
            'platform_code' => $this->account->platform_code,
            'project_code' => 'A003',
            'report_date' => '2026-06-06',
            'country' => 'US',
            'impressions' => 1,
            'clicks' => 1,
            'spend' => '1.000000',
            'ctr' => '1.000000',
            'cpm' => '1.000000',
            'cpc' => '1.000000',
            'raw_group_name' => 'old',
        ]);

        $service = app(AdSpendSyncService::class);
        $platformService = $this->mockPlatformService([
            $this->matchedRecord('2026-06-06', 'A003', impressions: 200, clicks: 25, spend: '35.500000'),
        ]);

        $service->syncAccount(
            $this->account,
            '2026-06-06',
            '2026-06-06',
            $platformService,
            AdSpendSyncService::SOURCE_MANUAL
        );

        $this->assertSame(1, AdSpendDailyReport::count());
        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-06',
            'country' => 'US',
            'platform' => '',
            'impressions' => 200,
            'clicks' => 25,
            'raw_group_name' => 'A003 campaign',
        ]);
    }

    public function test_sync_service_persists_remote_platform_dimension(): void
    {
        $service = app(AdSpendSyncService::class);
        $platformService = $this->mockPlatformService([
            $this->matchedRecord('2026-06-06', 'A003', platform: 'android'),
        ]);

        $job = $service->syncAccount(
            $this->account,
            '2026-06-06',
            '2026-06-06',
            $platformService,
            AdSpendSyncService::SOURCE_SCHEDULED
        );

        $this->assertContains('platform', $job->request_params['dims']);
        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-06',
            'country' => 'US',
            'platform' => 'android',
            'impressions' => 100,
            'clicks' => 10,
        ]);
    }

    public function test_sync_service_keeps_different_remote_platforms_separate(): void
    {
        $service = app(AdSpendSyncService::class);
        $platformService = $this->mockPlatformService([
            $this->matchedRecord('2026-06-06', 'A003', impressions: 100, clicks: 10, platform: 'android'),
            $this->matchedRecord('2026-06-06', 'A003', impressions: 200, clicks: 20, platform: 'ios'),
        ]);

        $service->syncAccount(
            $this->account,
            '2026-06-06',
            '2026-06-06',
            $platformService,
            AdSpendSyncService::SOURCE_SCHEDULED
        );

        $this->assertSame(2, AdSpendDailyReport::count());
        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-06',
            'country' => 'US',
            'platform' => 'android',
            'impressions' => 100,
        ]);
        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-06',
            'country' => 'US',
            'platform' => 'ios',
            'impressions' => 200,
        ]);
    }

    public function test_sync_service_normalizes_remote_platform_candidates_and_null_string(): void
    {
        $service = app(AdSpendSyncService::class);
        $platformService = $this->mockPlatformService([
            array_merge(
                $this->matchedRecord('2026-06-06', 'A003', impressions: 101),
                ['platform' => '', 'platform_name' => 'ios']
            ),
            array_merge(
                $this->matchedRecord('2026-06-07', 'A003', impressions: 102),
                ['devicePlatform' => 'android']
            ),
            array_merge(
                $this->matchedRecord('2026-06-08', 'A003', impressions: 103),
                ['device_platform' => 'web']
            ),
            $this->matchedRecord('2026-06-09', 'A003', impressions: 104, platform: 'null'),
        ]);

        $service->syncAccount(
            $this->account,
            '2026-06-06',
            '2026-06-09',
            $platformService,
            AdSpendSyncService::SOURCE_SCHEDULED
        );

        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-06',
            'country' => 'US',
            'platform' => 'ios',
            'impressions' => 101,
        ]);
        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-07',
            'country' => 'US',
            'platform' => 'android',
            'impressions' => 102,
        ]);
        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-08',
            'country' => 'US',
            'platform' => 'web',
            'impressions' => 103,
        ]);
        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-09',
            'country' => 'US',
            'platform' => '',
            'impressions' => 104,
        ]);
    }

    public function test_daily_query_supports_platform_list_filter_and_grouping(): void
    {
        AdSpendDailyReport::create($this->dailyReportRow([
            'platform' => 'android',
            'impressions' => 100,
            'clicks' => 10,
            'spend' => '20.000000',
        ]));
        AdSpendDailyReport::create($this->dailyReportRow([
            'platform' => 'ios',
            'impressions' => 200,
            'clicks' => 20,
            'spend' => '40.000000',
        ]));

        $service = app(AdSpendPlatformService::class);
        $filtered = $service->queryDaily([
            'dateFrom' => '2026-06-06',
            'dateTo' => '2026-06-06',
            'filters' => ['platforms' => ['android']],
            'page' => 1,
            'pageSize' => 20,
        ]);

        $this->assertSame(1, $filtered['total']);
        $this->assertSame('android', $filtered['data'][0]->platform);

        $grouped = $service->queryDaily([
            'dateFrom' => '2026-06-06',
            'dateTo' => '2026-06-06',
            'groupBy' => ['platform'],
            'page' => 1,
            'pageSize' => 20,
        ]);

        $platformRows = collect($grouped['data'])->keyBy('platform');
        $this->assertSame(100, (int) $platformRows['android']->impressions);
        $this->assertSame(200, (int) $platformRows['ios']->impressions);
    }

    public function test_command_sync_uses_shared_service_and_updates_today_data(): void
    {
        $this->app->instance(AdSpendPlatformService::class, $this->mockPlatformService([
            $this->matchedRecord('2026-06-06', 'A003', impressions: 300, clicks: 30, spend: '48.000000'),
        ]));

        $this->artisan('ad-spend:sync', [
            '--start-date' => '2026-06-06',
            '--end-date' => '2026-06-06',
            '--account-id' => [$this->account->id],
        ])->assertExitCode(0);
        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-06',
            'impressions' => 300,
            'clicks' => 30,
        ]);
    }

    public function test_command_delegates_to_shared_sync_service(): void
    {
        $platformService = $this->mockPlatformService([]);
        $syncJob = (new AdSpendSyncJob())->forceFill([
            'id' => 901,
            'total_records' => 1,
            'matched_records' => 1,
            'unmatched_records' => 0,
        ]);

        $syncService = Mockery::mock(AdSpendSyncService::class);
        $syncService->shouldReceive('syncAccount')
            ->once()
            ->withArgs(function ($account, $startDate, $endDate, $service, $source) use ($platformService) {
                return $account instanceof AdSpendPlatformAccount
                    && $account->id === $this->account->id
                    && $startDate === '2026-06-06'
                    && $endDate === '2026-06-06'
                    && $service === $platformService
                    && $source === AdSpendSyncService::SOURCE_SCHEDULED;
            })
            ->andReturn($syncJob);

        $this->app->instance(AdSpendPlatformService::class, $platformService);
        $this->app->instance(AdSpendSyncService::class, $syncService);

        $this->artisan('ad-spend:sync', [
            '--start-date' => '2026-06-06',
            '--end-date' => '2026-06-06',
            '--account-id' => [$this->account->id],
        ])->assertExitCode(0);
    }

    public function test_controller_sync_uses_shared_service_and_returns_job_id(): void
    {
        $controller = app(AdSpendPlatformController::class);
        $platformService = $this->mockPlatformService([
            $this->matchedRecord('2026-06-06', 'A003', impressions: 400, clicks: 40, spend: '60.000000'),
        ]);
        $syncService = app(AdSpendSyncService::class);

        $request = Request::create('/api/v3/test/ad-spend-platform/sync', 'POST', [
            'accountId' => $this->account->id,
            'startDate' => '2026-06-06',
            'endDate' => '2026-06-06',
        ]);

        $response = $controller->sync($request, $platformService, $syncService);
        $payload = $response->getData(true);

        $this->assertSame(0, $payload['code']);
        $this->assertNotEmpty($payload['data']['jobId']);
        $this->assertDatabaseHas('ad_spend_platform_daily_reports', [
            'platform_account_id' => $this->account->id,
            'project_code' => 'A003',
            'report_date' => '2026-06-06',
            'impressions' => 400,
            'clicks' => 40,
        ]);
    }

    public function test_controller_delegates_to_shared_sync_service_and_returns_job_id(): void
    {
        $controller = app(AdSpendPlatformController::class);
        $platformService = $this->mockPlatformService([]);
        $syncJob = (new AdSpendSyncJob())->forceFill([
            'id' => 321,
        ]);

        $syncService = Mockery::mock(AdSpendSyncService::class);
        $syncService->shouldReceive('syncAccount')
            ->once()
            ->withArgs(function ($account, $startDate, $endDate, $service, $source) use ($platformService) {
                return $account instanceof AdSpendPlatformAccount
                    && $account->id === $this->account->id
                    && $startDate === '2026-06-06'
                    && $endDate === '2026-06-06'
                    && $service === $platformService
                    && $source === AdSpendSyncService::SOURCE_MANUAL;
            })
            ->andReturn($syncJob);

        $request = Request::create('/api/v3/test/ad-spend-platform/sync', 'POST', [
            'accountId' => $this->account->id,
            'startDate' => '2026-06-06',
            'endDate' => '2026-06-06',
        ]);

        $response = $controller->sync($request, $platformService, $syncService);
        $payload = $response->getData(true);

        $this->assertSame(0, $payload['code']);
        $this->assertSame(321, $payload['data']['jobId']);
    }

    private function mockPlatformService(array $records): AdSpendPlatformService
    {
        $mock = Mockery::mock(AdSpendPlatformService::class);
        $mock->shouldReceive('fetchDailyRecordPages')
            ->andReturnUsing(function () use ($records) {
                yield $records;
            });
        $mock->shouldReceive('fetchDailyRecords')
            ->andReturn($records);

        return $mock;
    }

    private function matchedRecord(
        string $date,
        string $projectCode,
        int $impressions = 100,
        int $clicks = 10,
        string $spend = '20.500000',
        ?string $platform = null
    ): array {
        $record = [
            'date' => $date,
            'groupName' => $projectCode . ' campaign',
            'country' => 'US',
            'impressions' => $impressions,
            'clicks' => $clicks,
            'spend' => $spend,
            'ctr' => '10.000000',
            'cpm' => '5.000000',
            'cpc' => '2.050000',
        ];

        if ($platform !== null) {
            $record['platform'] = $platform;
        }

        return $record;
    }

    private function unmatchedRecord(string $date): array
    {
        return [
            'date' => $date,
            'groupName' => 'unknown campaign',
            'country' => 'US',
            'impressions' => 99,
            'clicks' => 9,
            'spend' => '12.000000',
            'ctr' => '9.090000',
            'cpm' => '4.000000',
            'cpc' => '1.333333',
        ];
    }

    private function dailyReportRow(array $overrides = []): array
    {
        return array_merge([
            'platform_account_id' => $this->account->id,
            'platform_code' => $this->account->platform_code,
            'platform' => '',
            'project_code' => 'A003',
            'report_date' => '2026-06-06',
            'country' => 'US',
            'impressions' => 1,
            'clicks' => 1,
            'spend' => '1.000000',
            'ctr' => '1.000000',
            'cpm' => '1.000000',
            'cpc' => '1.000000',
            'raw_group_name' => 'A003 campaign',
        ], $overrides);
    }
}
