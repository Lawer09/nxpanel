<?php

namespace Tests\Feature;

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectAppInfo;
use App\Models\ProjectUserAppMap;
use App\Services\AdRevenueService;
use App\Services\ProjectAppInfoService;
use App\Services\ProjectReportService;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectAppInfoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verify project app information can be created, filtered, updated, and deleted.
     */
    public function test_project_app_info_crud_and_duplicate_guard(): void
    {
        $project = Project::create([
            'project_code' => 'APP001',
            'project_name' => 'App Project',
            'status' => Project::STATUS_ACTIVE,
        ]);

        $service = app(ProjectAppInfoService::class);
        $appInfo = $service->store([
            'appId' => 'com.example.app',
            'appName' => 'Example App',
            'platform' => 'android',
            'downloadCount' => 1234,
            'downloadData' => [
                ['date' => '2026-07-05', 'downloads' => 100],
            ],
            'iconUrl' => 'https://example.com/icon.png',
            'chartUrl' => 'https://example.com/chart.png',
            'imageUrls' => ['https://example.com/a.png', 'https://example.com/a.png'],
            'storeUrl' => 'https://play.google.com/store/apps/details?id=com.example.app',
            'enabled' => 1,
        ]);

        $this->assertSame([['date' => '2026-07-05', 'downloads' => 100]], $appInfo->download_data);
        $this->assertSame(['https://example.com/a.png'], $appInfo->image_urls);

        $this->expectExceptionMessage('App info already exists');
        try {
            $service->store([
                'appId' => 'com.example.app',
            ]);
        } catch (\Throwable $e) {
            ProjectUserAppMap::create([
                'project_code' => 'APP001',
                'app_id' => 'com.example.app',
                'enabled' => 1,
            ]);
            $this->assertSame(1, $service->index(['projectCode' => 'APP001', 'keyword' => 'Example'])['total']);
            $updated = $service->update($appInfo->id, [
                'downloadCount' => 4321,
                'enabled' => 0,
                'remark' => 'disabled',
            ]);
            $this->assertSame(4321, $updated->download_count);
            $this->assertSame(0, $updated->enabled);

            $service->destroy($appInfo->id);
            $this->assertDatabaseMissing('app_infos', ['id' => $appInfo->id]);

            throw $e;
        }
    }

    /**
     * Verify project resources include loaded appInfos.
     */
    public function test_project_resource_returns_app_infos(): void
    {
        $project = Project::create([
            'project_code' => 'APP002',
            'project_name' => 'Resource Project',
            'status' => Project::STATUS_ACTIVE,
        ]);
        ProjectAppInfo::create([
            'app_id' => 'com.example.resource',
            'app_name' => 'Resource App',
            'download_count' => 88,
            'image_urls' => ['https://example.com/resource.png'],
        ]);
        ProjectUserAppMap::create([
            'project_code' => 'APP002',
            'app_id' => 'com.example.resource',
            'enabled' => 1,
        ]);

        $payload = ProjectResource::make(app(ProjectService::class)->detail((int) $project->id))->resolve(request());

        $this->assertCount(1, $payload['appInfos']);
        $this->assertSame('com.example.resource', $payload['appInfos'][0]['appId']);
        $this->assertSame(88, $payload['appInfos'][0]['downloadCount']);
    }

    /**
     * Verify project grouped daily and hourly reports attach appInfos only for project-code grouping.
     */
    public function test_project_reports_attach_app_infos_for_project_code_grouping(): void
    {
        Project::create([
            'project_code' => 'APP003',
            'project_name' => 'Report Project',
            'status' => Project::STATUS_ACTIVE,
        ]);
        ProjectAppInfo::create([
            'app_id' => 'com.example.report',
            'app_name' => 'Report App',
            'platform' => 'ios',
            'download_count' => 99,
            'icon_url' => 'https://example.com/report-icon.png',
        ]);
        ProjectUserAppMap::create([
            'project_code' => 'APP003',
            'app_id' => 'com.example.report',
            'enabled' => 1,
        ]);

        $this->insertDailyAggregate('2026-07-05', 'APP003');
        $this->insertHourlyAggregate('2026-07-05', 10, 'APP003');

        $service = new ProjectReportService($this->createMock(AdRevenueService::class));
        $daily = $service->queryDaily([
            'dateFrom' => '2026-07-05',
            'dateTo' => '2026-07-05',
            'groupBy' => ['projectCode'],
        ]);
        $dailyByDate = $service->queryDaily([
            'dateFrom' => '2026-07-05',
            'dateTo' => '2026-07-05',
            'groupBy' => ['reportDate'],
        ]);
        $hourly = $service->queryHourly([
            'dateFrom' => '2026-07-05',
            'dateTo' => '2026-07-05',
            'groupBy' => ['projectCode'],
        ]);

        $this->assertSame('com.example.report', $daily['data'][0]['appInfos'][0]['appId']);
        $this->assertArrayNotHasKey('appInfos', $dailyByDate['data'][0]);
        $this->assertSame('com.example.report', $hourly['data'][0]['appInfos'][0]['appId']);
    }

    private function insertDailyAggregate(string $date, string $projectCode): void
    {
        DB::table('project_daily_aggregates')->insert([
            'report_date' => $date,
            'project_code' => $projectCode,
            'country' => 'US',
            'new_users' => 10,
            'report_new_users' => 10,
            'fb_new_users' => 0,
            'dau_users' => 20,
            'fb_dau_users' => 0,
            'ad_revenue' => 15,
            'ad_requests' => 100,
            'ad_matched_requests' => 80,
            'ad_impressions' => 70,
            'ad_clicks' => 7,
            'traffic_usage_mb' => 1,
            'traffic_cost' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertHourlyAggregate(string $date, int $hour, string $projectCode): void
    {
        DB::table('project_report_hourly')->insert([
            'report_date' => $date,
            'hour' => $hour,
            'project_code' => $projectCode,
            'country' => 'US',
            'new_users' => 10,
            'report_new_users' => 10,
            'fb_new_users' => 0,
            'dau_users' => 20,
            'fb_dau_users' => 0,
            'ad_revenue' => 15,
            'ad_requests' => 100,
            'ad_matched_requests' => 80,
            'ad_impressions' => 70,
            'ad_clicks' => 7,
            'ad_spend_cost' => 5,
            'traffic_usage_mb' => 1,
            'traffic_cost' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
