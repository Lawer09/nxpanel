<?php

namespace App\Services;

use App\Models\ProjectAppInfo;
use App\Models\ProjectUserAppMap;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProjectReportService
{
    private const QUERY_CACHE_TTL = 60;

    private const QUERY_CACHE_VERSION_KEY = 'project_report:query_cache_version';

    private const RECENT_HOURLY_AD_MATCH_RATE_CACHE_TTL = 120;

    private const PROJECT_METADATA_COLUMNS = [
        'ad_status' => 'adStatus',
        'app_platform' => 'appPlatform',
    ];

    public function __construct(
        private readonly AdRevenueService $adRevenueService,
    ) {}

    /**
     * Invalidate cached project report query results after manual aggregation.
     */
    public function refreshQueryCache(): void
    {
        $currentVersion = (int) Cache::get(self::QUERY_CACHE_VERSION_KEY, 1);

        Cache::forever(self::QUERY_CACHE_VERSION_KEY, $currentVersion + 1);
    }

    /**
     * Query project daily aggregate report.
     */
    public function queryDaily(array $validated): array
    {
        $params = $this->buildProjectReportCacheParams('daily', $validated);

        return $this->rememberProjectReportQuery('daily', $params, fn () => $this->executeDailyQuery($params));
    }

    /**
     * Execute the project daily aggregate report query.
     */
    private function executeDailyQuery(array $validated): array
    {
        $definition = $this->buildDailyQueryDefinition($validated);
        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 50);

        $total = $definition['grouped']
            ? $this->countDailyGroups($definition)
            : (clone $definition['query'])->count();

        $rows = $definition['query']
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();
        $this->applyDailyNowRevenue($rows, $definition);
        $this->applyDailyDayOverDayMetrics($rows, $definition);
        if (($definition['includeLimitState'] ?? false) === true) {
            $this->applyDailyLimitState($rows);
        }
        if (($definition['includeRecentHourlyAdMatchRates'] ?? false) === true) {
            $this->applyRecentHourlyAdMatchRates($rows);
        }
        if (($definition['includeProjectAppInfos'] ?? false) === true) {
            $this->applyProjectAppInfos($rows);
        }
        $this->applyTopRevenueCountries($rows, 'daily', [
            'dateFrom' => $definition['dateFrom'],
            'dateTo' => $definition['dateTo'],
            'filters' => $definition['filters'] ?? [],
        ]);

        $data = $rows->map(fn ($row) => $this->formatDailyRow($row));

        return [
            'data' => $data,
            'summary' => $this->buildDailySummary($definition),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'dateFrom' => $definition['dateFrom'],
            'dateTo' => $definition['dateTo'],
            'groupBy' => $definition['requestedGroupBy'],
        ];
    }

    /**
     * Build the export filename for the project daily CSV.
     */
    public function makeDailyExportFilename(): string
    {
        return 'project_report_daily_' . now()->format('Ymd_His') . '.csv';
    }

    /**
     * Get fixed CSV headers for the project daily export.
     */
    public function dailyCsvHeaders(): array
    {
        return [
            '日期',
            '项目编码',
            '国家',
            '新增用户',
            '上报新增用户',
            'FB 新增用户',
            'DAU',
            'FB DAU',
            '广告收入',
            '广告请求数',
            '广告匹配请求数',
            '广告展示数',
            '广告点击数',
            'eCPM',
            'CTR',
            '匹配率',
            '展示率',
            '人均展示',
            'ARPU',
            '投放成本',
            'CPI',
            'CPC',
            'CPM',
            '流量用量 MB',
            '流量成本',
            '总成本',
            '流量成本占比',
            '利润',
            'ROI',
            '更新时间',
        ];
    }

    /**
     * Stream the project daily export rows to an open CSV handle.
     *
     * @param resource $output
     */
    public function writeDailyCsvRows(array $validated, $output): void
    {
        $definition = $this->buildDailyQueryDefinition($validated);

        foreach ($definition['query']->cursor() as $row) {
            fputcsv($output, $this->formatDailyCsvRow($this->formatDailyRow($row)));
        }
    }

    /**
     * Query project hourly report.
     */
    public function queryHourly(array $validated): array
    {
        $params = $this->buildProjectReportCacheParams('hourly', $validated);

        return $this->rememberProjectReportQuery('hourly', $params, fn () => $this->executeHourlyQuery($params));
    }

    /**
     * Query hourly ad match rate for a single project over a date range.
     */
    public function queryHourlyAdMatchRate(array $validated): array
    {
        $projectCode = trim((string) $validated['projectCode']);
        $dateFrom = (string) $validated['dateFrom'];
        $dateTo = (string) $validated['dateTo'];

        $rows = DB::table('project_report_hourly')
            ->where('project_code', '=', $projectCode)
            ->where('report_date', '>=', $dateFrom)
            ->where('report_date', '<=', $dateTo)
            ->selectRaw('report_date')
            ->selectRaw('hour')
            ->selectRaw('SUM(ad_requests) as ad_requests')
            ->selectRaw('SUM(ad_matched_requests) as ad_matched_requests')
            ->groupBy('report_date', 'hour')
            ->orderBy('report_date')
            ->orderBy('hour')
            ->get();

        $data = $rows->map(function ($row) use ($projectCode) {
            $adRequests = (int) ($row->ad_requests ?? 0);
            $adMatchedRequests = (int) ($row->ad_matched_requests ?? 0);
            $reportDate = (string) $row->report_date;
            $hour = (int) $row->hour;

            return [
                'reportDate' => $reportDate,
                'hour' => $hour,
                'hourStart' => sprintf('%s %02d:00:00', $reportDate, $hour),
                'projectCode' => $projectCode,
                'adRequests' => $adRequests,
                'adMatchedRequests' => $adMatchedRequests,
                'adMatchRate' => $adRequests === 0
                    ? null
                    : $this->formatDecimal($adMatchedRequests / $adRequests * 100),
            ];
        })->values();

        return [
            'projectCode' => $projectCode,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'data' => $data,
        ];
    }

    /**
     * Execute the project hourly report query.
     */
    private function executeHourlyQuery(array $validated): array
    {
        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $dimensionMap = [
            'reportDate' => 'project_report_hourly.report_date',
            'hour' => 'project_report_hourly.hour',
            'projectCode' => 'project_report_hourly.project_code',
            'country' => 'project_report_hourly.country',
        ];

        $metricMap = [
            'newUsers' => 'new_users',
            'reportNewUsers' => 'report_new_users',
            'fbNewUsers' => 'fb_new_users',
            'dauUsers' => 'dau_users',
            'fbDauUsers' => 'fb_dau_users',
            'adRevenue' => 'ad_revenue',
            'adRequests' => 'ad_requests',
            'adMatchedRequests' => 'ad_matched_requests',
            'adImpressions' => 'ad_impressions',
            'adClicks' => 'ad_clicks',
            'adEcpm' => 'ad_ecpm',
            'adCtr' => 'ad_ctr',
            'adMatchRate' => 'ad_match_rate',
            'adShowRate' => 'ad_show_rate',
            'adSpendCost' => 'ad_spend_cost',
            'adSpendCpi' => 'ad_spend_cpi',
            'adSpendCpc' => 'ad_spend_cpc',
            'adSpendCpm' => 'ad_spend_cpm',
            'trafficUsageMb' => 'traffic_usage_mb',
            'trafficCost' => 'traffic_cost',
            'totalCost' => 'total_cost',
            'trafficCostRatio' => 'traffic_cost_ratio',
            'profit' => 'profit',
            'roi' => 'roi',
            'id' => 'id',
            'updatedAt' => 'updated_at',
        ];

        $query = DB::table('project_report_hourly')
            ->where('project_report_hourly.report_date', '>=', $dateFrom)
            ->where('project_report_hourly.report_date', '<=', $dateTo);

        if ($hourFrom !== null) {
            $query->where('project_report_hourly.hour', '>=', (int) $hourFrom);
        }
        if ($hourTo !== null) {
            $query->where('project_report_hourly.hour', '<=', (int) $hourTo);
        }

        $this->applyProjectCodeCountryFilters($query, 'project_report_hourly.project_code', 'project_report_hourly.country', $filters);
        $this->applyProjectAdStatusFilter($query, 'project_report_hourly.project_code', $filters);
        $this->applyProjectAppPlatformFilter($query, 'project_report_hourly.project_code', $filters);
        $this->applyProjectDepartmentFilter($query, 'project_report_hourly.project_code', $filters);

        $summaryQuery = clone $query;
        $includeProjectAppInfos = false;

        if (empty($groupBy)) {
            $sortable = array_merge(array_keys($dimensionMap), array_keys($metricMap));
            $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'reportDate';

            $total = (clone $query)->count();
            $this->joinHourlyProjectMetadata($query);
            $query->select([
                'project_report_hourly.id',
                'project_report_hourly.report_date',
                'project_report_hourly.hour',
                'project_report_hourly.project_code',
                'project_report_hourly.country',
                'project_report_hourly.new_users',
                'project_report_hourly.report_new_users',
                'project_report_hourly.fb_new_users',
                'project_report_hourly.dau_users',
                'project_report_hourly.fb_dau_users',
                'project_report_hourly.ad_revenue',
                'project_report_hourly.ad_requests',
                'project_report_hourly.ad_matched_requests',
                'project_report_hourly.ad_impressions',
                'project_report_hourly.ad_clicks',
                'project_report_hourly.ad_ecpm',
                'project_report_hourly.ad_ctr',
                'project_report_hourly.ad_match_rate',
                'project_report_hourly.ad_show_rate',
                'project_report_hourly.ad_spend_cost',
                'project_report_hourly.ad_spend_cpi',
                'project_report_hourly.ad_spend_cpc',
                'project_report_hourly.ad_spend_cpm',
                'project_report_hourly.traffic_usage_mb',
                'project_report_hourly.traffic_cost',
                'project_report_hourly.profit',
                'project_report_hourly.roi',
                'project_report_hourly.updated_at',
            ]);
            $this->selectProjectMetadata($query);
            $query->selectRaw('(project_report_hourly.ad_spend_cost + project_report_hourly.traffic_cost) as total_cost')
                ->selectRaw('CASE WHEN (COALESCE(project_report_hourly.ad_spend_cost, 0)+COALESCE(project_report_hourly.traffic_cost, 0))=0 THEN NULL ELSE ROUND(COALESCE(project_report_hourly.traffic_cost, 0)/(COALESCE(project_report_hourly.ad_spend_cost, 0)+COALESCE(project_report_hourly.traffic_cost, 0)),6) END as traffic_cost_ratio');

            if ($orderKey === 'id') {
                $query->orderBy('project_report_hourly.id', $orderDirection);
            } elseif ($orderKey === 'updatedAt') {
                $query->orderBy('project_report_hourly.updated_at', $orderDirection);
            } else {
                $this->applyDailyOrder($query, $orderKey, $orderDirection, $dimensionMap, $metricMap, 'project_report_hourly.report_date', true);
            }

            $rows = $query
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
        } else {
            $groupDimensions = array_values(array_unique(array_filter($groupBy, static fn ($item) => is_string($item) && isset($dimensionMap[$item]))));
            if (empty($groupDimensions)) {
                $groupDimensions = ['reportDate', 'hour'];
            }
            $includeProjectAppInfos = in_array('projectCode', $groupDimensions, true);

            $groupColumns = array_map(static fn ($key) => $dimensionMap[$key], $groupDimensions);
            $groupQuery = clone $query;
            if ($includeProjectAppInfos) {
                $this->joinHourlyProjectMetadata($groupQuery);
            }
            foreach ($groupColumns as $groupColumn) {
                $groupQuery->selectRaw($groupColumn . ' as ' . $this->hourlyDimensionAlias(array_search($groupColumn, $dimensionMap, true) ?: ''));
                $groupQuery->groupBy($groupColumn);
            }

            $groupQuery->selectRaw('SUM(project_report_hourly.new_users) as new_users')
                ->selectRaw('SUM(project_report_hourly.report_new_users) as report_new_users')
                ->selectRaw('SUM(project_report_hourly.fb_new_users) as fb_new_users')
                ->selectRaw('SUM(project_report_hourly.dau_users) as dau_users')
                ->selectRaw('SUM(project_report_hourly.fb_dau_users) as fb_dau_users')
                ->selectRaw('SUM(project_report_hourly.ad_revenue) as ad_revenue')
                ->selectRaw('SUM(project_report_hourly.ad_requests) as ad_requests')
                ->selectRaw('SUM(project_report_hourly.ad_matched_requests) as ad_matched_requests')
                ->selectRaw('SUM(project_report_hourly.ad_impressions) as ad_impressions')
                ->selectRaw('SUM(project_report_hourly.ad_clicks) as ad_clicks')
                ->selectRaw('SUM(project_report_hourly.ad_spend_cost) as ad_spend_cost')
                ->selectRaw('SUM(project_report_hourly.traffic_usage_mb) as traffic_usage_mb')
                ->selectRaw('SUM(project_report_hourly.traffic_cost) as traffic_cost')
                ->selectRaw('(SUM(project_report_hourly.ad_spend_cost) + SUM(project_report_hourly.traffic_cost)) as total_cost')
                ->selectRaw('CASE WHEN (COALESCE(SUM(project_report_hourly.ad_spend_cost), 0)+COALESCE(SUM(project_report_hourly.traffic_cost), 0))=0 THEN NULL ELSE ROUND(COALESCE(SUM(project_report_hourly.traffic_cost), 0)/(COALESCE(SUM(project_report_hourly.ad_spend_cost), 0)+COALESCE(SUM(project_report_hourly.traffic_cost), 0)),6) END as traffic_cost_ratio')
                ->selectRaw('(SUM(project_report_hourly.ad_revenue) - SUM(project_report_hourly.ad_spend_cost) - SUM(project_report_hourly.traffic_cost)) as profit')
                ->selectRaw('MAX(project_report_hourly.updated_at) as updated_at')
                ->selectRaw('CASE WHEN SUM(project_report_hourly.ad_impressions)=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_revenue)/SUM(project_report_hourly.ad_impressions)*1000,6) END as ad_ecpm')
                ->selectRaw('CASE WHEN SUM(project_report_hourly.ad_impressions)=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_clicks)/SUM(project_report_hourly.ad_impressions)*100,6) END as ad_ctr')
                ->selectRaw('CASE WHEN SUM(project_report_hourly.ad_requests)=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_matched_requests)/SUM(project_report_hourly.ad_requests)*100,6) END as ad_match_rate')
                ->selectRaw('CASE WHEN SUM(project_report_hourly.ad_matched_requests)=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_impressions)/SUM(project_report_hourly.ad_matched_requests)*100,6) END as ad_show_rate')
                ->selectRaw('CASE WHEN SUM(project_report_hourly.new_users)=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_spend_cost)/SUM(project_report_hourly.new_users),6) END as ad_spend_cpi')
                ->selectRaw('NULL as ad_spend_cpc')
                ->selectRaw('NULL as ad_spend_cpm')
                ->selectRaw('CASE WHEN (SUM(project_report_hourly.ad_spend_cost)+SUM(project_report_hourly.traffic_cost))=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_revenue)/(SUM(project_report_hourly.ad_spend_cost)+SUM(project_report_hourly.traffic_cost)),6) END as roi');

            if ($includeProjectAppInfos) {
                $this->selectGroupedProjectMetadata($groupQuery);
            }

            $sortable = array_values(array_unique(array_merge($groupDimensions, [
                'newUsers', 'reportNewUsers', 'fbNewUsers', 'dauUsers', 'fbDauUsers', 'adRevenue', 'adRequests', 'adMatchedRequests',
                'adImpressions', 'adClicks', 'adEcpm', 'adCtr', 'adMatchRate', 'adShowRate',
                'adSpendCost', 'adSpendCpi', 'adSpendCpc', 'adSpendCpm', 'trafficUsageMb',
                'trafficCost', 'totalCost', 'trafficCostRatio', 'profit', 'roi', 'updatedAt',
            ])));

            $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'adRevenue';

            $countQuery = DB::table(DB::raw("({$groupQuery->toSql()}) as t"))
                ->mergeBindings($groupQuery)
                ->selectRaw('COUNT(*) as cnt')
                ->first();
            $total = (int) ($countQuery->cnt ?? 0);

            $this->applyDailyOrder($groupQuery, $orderKey, $orderDirection, $dimensionMap, $metricMap, 'ad_revenue', true);

            $rows = $groupQuery
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
        }

        if ($includeProjectAppInfos) {
            $this->applyProjectAppInfos($rows);
        }

        $this->applyTopRevenueCountries($rows, 'hourly', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'filters' => $filters,
        ]);

        $data = $rows->map(fn ($row) => $this->formatHourlyRow($row));

        return [
            'data' => $data,
            'summary' => $this->buildHourlySummary($summaryQuery),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ];
    }

    /**
     * Build project hourly summary from the full filtered hourly query, independent of pagination.
     */
    private function buildHourlySummary(Builder $baseQuery): array
    {
        $row = (clone $baseQuery)
            ->selectRaw('SUM(project_report_hourly.new_users) as new_users')
            ->selectRaw('SUM(project_report_hourly.report_new_users) as report_new_users')
            ->selectRaw('SUM(project_report_hourly.fb_new_users) as fb_new_users')
            ->selectRaw('SUM(project_report_hourly.dau_users) as dau_users')
            ->selectRaw('SUM(project_report_hourly.fb_dau_users) as fb_dau_users')
            ->selectRaw('SUM(project_report_hourly.ad_revenue) as ad_revenue')
            ->selectRaw('SUM(project_report_hourly.ad_requests) as ad_requests')
            ->selectRaw('SUM(project_report_hourly.ad_matched_requests) as ad_matched_requests')
            ->selectRaw('SUM(project_report_hourly.ad_impressions) as ad_impressions')
            ->selectRaw('SUM(project_report_hourly.ad_clicks) as ad_clicks')
            ->selectRaw('SUM(project_report_hourly.ad_spend_cost) as ad_spend_cost')
            ->selectRaw('SUM(project_report_hourly.traffic_usage_mb) as traffic_usage_mb')
            ->selectRaw('SUM(project_report_hourly.traffic_cost) as traffic_cost')
            ->selectRaw('(SUM(project_report_hourly.ad_revenue) - SUM(project_report_hourly.ad_spend_cost) - SUM(project_report_hourly.traffic_cost)) as profit')
            ->selectRaw('MAX(project_report_hourly.updated_at) as updated_at')
            ->selectRaw('CASE WHEN SUM(project_report_hourly.ad_impressions)=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_revenue)/SUM(project_report_hourly.ad_impressions)*1000,6) END as ad_ecpm')
            ->selectRaw('CASE WHEN SUM(project_report_hourly.ad_impressions)=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_clicks)/SUM(project_report_hourly.ad_impressions)*100,6) END as ad_ctr')
            ->selectRaw('CASE WHEN SUM(project_report_hourly.ad_requests)=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_matched_requests)/SUM(project_report_hourly.ad_requests)*100,6) END as ad_match_rate')
            ->selectRaw('CASE WHEN SUM(project_report_hourly.ad_matched_requests)=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_impressions)/SUM(project_report_hourly.ad_matched_requests)*100,6) END as ad_show_rate')
            ->selectRaw('CASE WHEN SUM(project_report_hourly.new_users)=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_spend_cost)/SUM(project_report_hourly.new_users),6) END as ad_spend_cpi')
            ->selectRaw('NULL as ad_spend_cpc')
            ->selectRaw('NULL as ad_spend_cpm')
            ->selectRaw('CASE WHEN (COALESCE(SUM(project_report_hourly.ad_spend_cost), 0)+COALESCE(SUM(project_report_hourly.traffic_cost), 0))=0 THEN NULL ELSE ROUND(COALESCE(SUM(project_report_hourly.traffic_cost), 0)/(COALESCE(SUM(project_report_hourly.ad_spend_cost), 0)+COALESCE(SUM(project_report_hourly.traffic_cost), 0)),6) END as traffic_cost_ratio')
            ->selectRaw('CASE WHEN (SUM(project_report_hourly.ad_spend_cost)+SUM(project_report_hourly.traffic_cost))=0 THEN NULL ELSE ROUND(SUM(project_report_hourly.ad_revenue)/(SUM(project_report_hourly.ad_spend_cost)+SUM(project_report_hourly.traffic_cost)),6) END as roi')
            ->first();

        return [
            'newUsers' => (int) ($row->new_users ?? 0),
            'reportNewUsers' => (int) ($row->report_new_users ?? 0),
            'fbNewUsers' => (int) ($row->fb_new_users ?? 0),
            'dauUsers' => (int) ($row->dau_users ?? 0),
            'fbDauUsers' => (int) ($row->fb_dau_users ?? 0),
            'adRevenue' => $this->formatDecimal($row->ad_revenue ?? null),
            'adRequests' => (int) ($row->ad_requests ?? 0),
            'adMatchedRequests' => (int) ($row->ad_matched_requests ?? 0),
            'adImpressions' => (int) ($row->ad_impressions ?? 0),
            'adClicks' => (int) ($row->ad_clicks ?? 0),
            'adEcpm' => $this->formatDecimal($row->ad_ecpm ?? null),
            'adCtr' => $this->formatDecimal($row->ad_ctr ?? null),
            'adMatchRate' => $this->formatDecimal($row->ad_match_rate ?? null),
            'adShowRate' => $this->formatDecimal($row->ad_show_rate ?? null),
            'impressionsPerUser' => $this->ratio((float) ($row->ad_impressions ?? 0), (float) ($row->dau_users ?? 0)),
            'arpu' => $this->ratio((float) ($row->ad_revenue ?? 0), (float) ($row->dau_users ?? 0)),
            'adSpendCost' => $this->formatDecimal($row->ad_spend_cost ?? null),
            'adSpendCpi' => $this->formatDecimal($row->ad_spend_cpi ?? null),
            'adSpendCpc' => $this->formatDecimal($row->ad_spend_cpc ?? null),
            'adSpendCpm' => $this->formatDecimal($row->ad_spend_cpm ?? null),
            'trafficUsageMb' => $this->formatDecimal($row->traffic_usage_mb ?? null),
            'trafficCost' => $this->formatDecimal($row->traffic_cost ?? null),
            'totalCost' => $this->formatDecimal(($row->ad_spend_cost ?? 0) + ($row->traffic_cost ?? 0)),
            'trafficCostRatio' => $this->formatDecimal($row->traffic_cost_ratio ?? null),
            'profit' => $this->formatDecimal($row->profit ?? null),
            'roi' => $this->formatDecimal($row->roi ?? null),
            'updatedAt' => $row->updated_at ?? null,
        ];
    }

    /**
     * Cache project report JSON query results for a short TTL.
     */
    private function rememberProjectReportQuery(string $scope, array $params, callable $callback): array
    {
        $cacheKey = $this->makeProjectReportCacheKey($scope, $params);

        return Cache::remember($cacheKey, self::QUERY_CACHE_TTL, $callback);
    }

    /**
     * Build a stable cache key for project report query parameters.
     */
    private function makeProjectReportCacheKey(string $scope, array $params): string
    {
        $normalized = $this->normalizeCacheValue($params);
        $payload = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $version = (int) Cache::get(self::QUERY_CACHE_VERSION_KEY, 1);

        return sprintf('project_report:%s_query:v13:%d:%s', $scope, $version, md5((string) $payload));
    }

    /**
     * Resolve effective query parameters before generating the cache key.
     */
    private function buildProjectReportCacheParams(string $scope, array $validated): array
    {
        $params = [
            'dateFrom' => $validated['dateFrom'] ?? now()->subDays(1)->toDateString(),
            'dateTo' => $validated['dateTo'] ?? now()->toDateString(),
            'groupBy' => is_array($validated['groupBy'] ?? null) ? array_values($validated['groupBy']) : [],
            'filters' => is_array($validated['filters'] ?? null) ? $this->normalizeCacheFilters($validated['filters']) : [],
            'page' => (int) ($validated['page'] ?? 1),
            'pageSize' => (int) ($validated['pageSize'] ?? 50),
            'orderBy' => is_string($validated['orderBy'] ?? null) ? $validated['orderBy'] : null,
            'orderDirection' => $this->normalizeOrderDirection($validated['orderDirection'] ?? null),
        ];

        if ($scope === 'hourly') {
            $params['hourFrom'] = isset($validated['hourFrom']) ? (int) $validated['hourFrom'] : null;
            $params['hourTo'] = isset($validated['hourTo']) ? (int) $validated['hourTo'] : null;
        } else {
            $now = now('Asia/Shanghai');
            $params['dayOverDayHourBucket'] = $now->format('YmdH');
            $params['dayOverDayHourTo'] = $this->currentDayOverDayHourTo($now);
        }

        return $params;
    }

    /**
     * Normalize filters so semantically identical requests share the same cache key.
     */
    private function normalizeCacheFilters(array $filters): array
    {
        foreach (['projectCodes', 'countries', 'adStatuses', 'appPlatforms', 'departments'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = $this->normalizeStringList($filters[$key]);
                if ($key === 'countries') {
                    $filters[$key] = array_map(static fn ($country) => strtoupper($country), $filters[$key]);
                }
                sort($filters[$key], SORT_STRING);
            }
        }

        if (isset($filters['exclude']) && is_array($filters['exclude'])) {
            foreach (['projectCodes', 'countries'] as $key) {
                if (array_key_exists($key, $filters['exclude'])) {
                    $filters['exclude'][$key] = $this->normalizeStringList($filters['exclude'][$key]);
                    if ($key === 'countries') {
                        $filters['exclude'][$key] = array_map(static fn ($country) => strtoupper($country), $filters['exclude'][$key]);
                    }
                    sort($filters['exclude'][$key], SORT_STRING);
                }
            }
        }

        return $this->normalizeCacheValue($filters);
    }

    /**
     * Recursively sort associative arrays while preserving list order.
     */
    private function normalizeCacheValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalizeCacheValue($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeCacheValue($item);
        }

        return $value;
    }

    /**
     * Build the reusable daily report query definition for query and export.
     */
    private function buildDailyQueryDefinition(array $validated): array
    {
        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $requestedGroupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);
        $dayOverDayHourTo = array_key_exists('dayOverDayHourTo', $validated)
            ? $validated['dayOverDayHourTo']
            : $this->currentDayOverDayHourTo();

        $dimensionMap = [
            'reportDate' => 'project_daily_aggregates.report_date',
            'projectCode' => 'project_daily_aggregates.project_code',
            'country' => 'project_daily_aggregates.country',
        ];

        $metricMap = [
            'newUsers' => 'new_users',
            'reportNewUsers' => 'report_new_users',
            'fbNewUsers' => 'fb_new_users',
            'dauUsers' => 'dau_users',
            'fbDauUsers' => 'fb_dau_users',
            'adRevenue' => 'ad_revenue',
            'adRequests' => 'ad_requests',
            'adMatchedRequests' => 'ad_matched_requests',
            'adImpressions' => 'ad_impressions',
            'adClicks' => 'ad_clicks',
            'adEcpm' => 'ad_ecpm',
            'adCtr' => 'ad_ctr',
            'adMatchRate' => 'ad_match_rate',
            'adShowRate' => 'ad_show_rate',
            'adSpendCost' => 'ad_spend_cost',
            'adSpendCpi' => 'ad_spend_cpi',
            'adSpendCpc' => 'ad_spend_cpc',
            'adSpendCpm' => 'ad_spend_cpm',
            'trafficUsageMb' => 'traffic_usage_mb',
            'trafficCost' => 'traffic_cost',
            'totalCost' => 'total_cost',
            'trafficCostRatio' => 'traffic_cost_ratio',
            'profit' => 'profit',
            'roi' => 'roi',
            'id' => 'id',
            'updatedAt' => 'updated_at',
        ];

        $baseQuery = DB::table('project_daily_aggregates')
            ->where('project_daily_aggregates.report_date', '>=', $dateFrom)
            ->where('project_daily_aggregates.report_date', '<=', $dateTo);

        $this->applyProjectCodeCountryFilters($baseQuery, 'project_daily_aggregates.project_code', 'project_daily_aggregates.country', $filters);
        $this->applyProjectAdStatusFilter($baseQuery, 'project_daily_aggregates.project_code', $filters);
        $this->applyProjectAppPlatformFilter($baseQuery, 'project_daily_aggregates.project_code', $filters);
        $this->applyProjectDepartmentFilter($baseQuery, 'project_daily_aggregates.project_code', $filters);

        $spendMetricsQuery = $this->buildDailySpendMetricSubquery($dateFrom, $dateTo, $filters);

        if (empty($requestedGroupBy)) {
            $sortable = array_merge(array_keys($dimensionMap), array_keys($metricMap));
            $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'reportDate';
            $query = clone $baseQuery;
            $this->joinDailySpendMetrics($query, $spendMetricsQuery);
            $this->joinProjectMetadata($query);

            $query->select([
                'project_daily_aggregates.id',
                'project_daily_aggregates.report_date',
                'project_daily_aggregates.project_code',
                'project_daily_aggregates.country',
                'project_daily_aggregates.new_users',
                'project_daily_aggregates.report_new_users',
                'project_daily_aggregates.fb_new_users',
                'project_daily_aggregates.dau_users',
                'project_daily_aggregates.fb_dau_users',
                'project_daily_aggregates.ad_revenue',
                'project_daily_aggregates.ad_requests',
                'project_daily_aggregates.ad_matched_requests',
                'project_daily_aggregates.ad_impressions',
                'project_daily_aggregates.ad_clicks',
                'project_daily_aggregates.ad_ecpm',
                'project_daily_aggregates.ad_ctr',
                'project_daily_aggregates.ad_match_rate',
                'project_daily_aggregates.ad_show_rate',
                'project_daily_aggregates.traffic_usage_mb',
                'project_daily_aggregates.traffic_cost',
                'project_daily_aggregates.updated_at',
            ]);
            $this->selectProjectMetadata($query);
            $query->selectRaw('COALESCE(spend_metrics.ad_spend_cost, 0) as ad_spend_cost')
                ->selectRaw('spend_metrics.ad_spend_clicks as ad_spend_clicks')
                ->selectRaw('spend_metrics.ad_spend_impressions as ad_spend_impressions')
                ->selectRaw('CASE WHEN project_daily_aggregates.new_users=0 THEN NULL ELSE ROUND(COALESCE(spend_metrics.ad_spend_cost, 0)/project_daily_aggregates.new_users,6) END as ad_spend_cpi')
                ->selectRaw('CASE WHEN COALESCE(spend_metrics.ad_spend_clicks, 0)=0 THEN NULL ELSE ROUND(COALESCE(spend_metrics.ad_spend_cost, 0)/spend_metrics.ad_spend_clicks,6) END as ad_spend_cpc')
                ->selectRaw('CASE WHEN COALESCE(spend_metrics.ad_spend_impressions, 0)=0 THEN NULL ELSE ROUND(COALESCE(spend_metrics.ad_spend_cost, 0)*1000/spend_metrics.ad_spend_impressions,6) END as ad_spend_cpm')
                ->selectRaw('(COALESCE(spend_metrics.ad_spend_cost, 0) + project_daily_aggregates.traffic_cost) as total_cost')
                ->selectRaw('CASE WHEN (COALESCE(spend_metrics.ad_spend_cost, 0)+COALESCE(project_daily_aggregates.traffic_cost, 0))=0 THEN NULL ELSE ROUND(COALESCE(project_daily_aggregates.traffic_cost, 0)/(COALESCE(spend_metrics.ad_spend_cost, 0)+COALESCE(project_daily_aggregates.traffic_cost, 0)),6) END as traffic_cost_ratio')
                ->selectRaw('(project_daily_aggregates.ad_revenue - COALESCE(spend_metrics.ad_spend_cost, 0) - project_daily_aggregates.traffic_cost) as profit')
                ->selectRaw('CASE WHEN (COALESCE(spend_metrics.ad_spend_cost, 0)+project_daily_aggregates.traffic_cost)=0 THEN NULL ELSE ROUND(project_daily_aggregates.ad_revenue/(COALESCE(spend_metrics.ad_spend_cost, 0)+project_daily_aggregates.traffic_cost),6) END as roi');

            $this->applyDailyOrder($query, $orderKey, $orderDirection, $dimensionMap, $metricMap, 'report_date', true);

            return [
                'baseQuery' => $baseQuery,
                'query' => $query,
                'grouped' => false,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'filters' => $filters,
                'requestedGroupBy' => $requestedGroupBy,
                'groupDimensions' => [],
                'dayOverDayHourTo' => $dayOverDayHourTo,
            ];
        }

        $groupDimensions = array_values(array_unique(array_filter($requestedGroupBy, static fn ($item) => is_string($item) && isset($dimensionMap[$item]))));
        if (empty($groupDimensions)) {
            $groupDimensions = ['reportDate'];
        }

        $query = clone $baseQuery;
        $this->joinDailySpendMetrics($query, $spendMetricsQuery);
        if (in_array('projectCode', $groupDimensions, true)) {
            $this->joinProjectMetadata($query);
        }

        foreach ($groupDimensions as $groupDimension) {
            $groupColumn = $dimensionMap[$groupDimension];
            $query->selectRaw($groupColumn . ' as ' . $this->dailyDimensionAlias($groupDimension));
            $query->groupBy($groupColumn);
        }

        $query->selectRaw('SUM(project_daily_aggregates.new_users) as new_users')
            ->selectRaw('SUM(project_daily_aggregates.report_new_users) as report_new_users')
            ->selectRaw('SUM(project_daily_aggregates.fb_new_users) as fb_new_users')
            ->selectRaw('SUM(project_daily_aggregates.dau_users) as dau_users')
            ->selectRaw('SUM(project_daily_aggregates.fb_dau_users) as fb_dau_users')
            ->selectRaw('SUM(project_daily_aggregates.ad_revenue) as ad_revenue')
            ->selectRaw('SUM(project_daily_aggregates.ad_requests) as ad_requests')
            ->selectRaw('SUM(project_daily_aggregates.ad_matched_requests) as ad_matched_requests')
            ->selectRaw('SUM(project_daily_aggregates.ad_impressions) as ad_impressions')
            ->selectRaw('SUM(project_daily_aggregates.ad_clicks) as ad_clicks')
            ->selectRaw('COALESCE(SUM(spend_metrics.ad_spend_cost), 0) as ad_spend_cost')
            ->selectRaw('SUM(spend_metrics.ad_spend_clicks) as ad_spend_clicks')
            ->selectRaw('SUM(spend_metrics.ad_spend_impressions) as ad_spend_impressions')
            ->selectRaw('SUM(project_daily_aggregates.traffic_usage_mb) as traffic_usage_mb')
            ->selectRaw('SUM(project_daily_aggregates.traffic_cost) as traffic_cost')
            ->selectRaw('(COALESCE(SUM(spend_metrics.ad_spend_cost), 0) + SUM(project_daily_aggregates.traffic_cost)) as total_cost')
            ->selectRaw('CASE WHEN (COALESCE(SUM(spend_metrics.ad_spend_cost), 0)+COALESCE(SUM(project_daily_aggregates.traffic_cost), 0))=0 THEN NULL ELSE ROUND(COALESCE(SUM(project_daily_aggregates.traffic_cost), 0)/(COALESCE(SUM(spend_metrics.ad_spend_cost), 0)+COALESCE(SUM(project_daily_aggregates.traffic_cost), 0)),6) END as traffic_cost_ratio')
            ->selectRaw('(SUM(project_daily_aggregates.ad_revenue) - COALESCE(SUM(spend_metrics.ad_spend_cost), 0) - SUM(project_daily_aggregates.traffic_cost)) as profit')
            ->selectRaw('MAX(project_daily_aggregates.updated_at) as updated_at')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_impressions)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_revenue)/SUM(project_daily_aggregates.ad_impressions)*1000,6) END as ad_ecpm')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_impressions)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_clicks)/SUM(project_daily_aggregates.ad_impressions)*100,6) END as ad_ctr')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_requests)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_matched_requests)/SUM(project_daily_aggregates.ad_requests)*100,6) END as ad_match_rate')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_matched_requests)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_impressions)/SUM(project_daily_aggregates.ad_matched_requests)*100,6) END as ad_show_rate')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.new_users)=0 THEN NULL ELSE ROUND(COALESCE(SUM(spend_metrics.ad_spend_cost), 0)/SUM(project_daily_aggregates.new_users),6) END as ad_spend_cpi')
            ->selectRaw('CASE WHEN COALESCE(SUM(spend_metrics.ad_spend_clicks), 0)=0 THEN NULL ELSE ROUND(COALESCE(SUM(spend_metrics.ad_spend_cost), 0)/SUM(spend_metrics.ad_spend_clicks),6) END as ad_spend_cpc')
            ->selectRaw('CASE WHEN COALESCE(SUM(spend_metrics.ad_spend_impressions), 0)=0 THEN NULL ELSE ROUND(COALESCE(SUM(spend_metrics.ad_spend_cost), 0)*1000/SUM(spend_metrics.ad_spend_impressions),6) END as ad_spend_cpm')
            ->selectRaw('CASE WHEN (COALESCE(SUM(spend_metrics.ad_spend_cost), 0)+SUM(project_daily_aggregates.traffic_cost))=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_revenue)/(COALESCE(SUM(spend_metrics.ad_spend_cost), 0)+SUM(project_daily_aggregates.traffic_cost)),6) END as roi');

        if (in_array('projectCode', $groupDimensions, true)) {
            $this->selectGroupedProjectMetadata($query);
        }

        $sortable = array_values(array_unique(array_merge($groupDimensions, [
            'newUsers', 'reportNewUsers', 'fbNewUsers', 'dauUsers', 'fbDauUsers', 'adRevenue', 'adRequests', 'adMatchedRequests',
            'adImpressions', 'adClicks', 'adEcpm', 'adCtr', 'adMatchRate', 'adShowRate',
            'adSpendCost', 'adSpendCpi', 'adSpendCpc', 'adSpendCpm', 'trafficUsageMb',
            'trafficCost', 'totalCost', 'trafficCostRatio', 'profit', 'roi', 'updatedAt',
        ])));

        $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'adRevenue';
        $this->applyDailyOrder($query, $orderKey, $orderDirection, $dimensionMap, $metricMap, 'ad_revenue', true);

        return [
            'baseQuery' => $baseQuery,
            'query' => $query,
            'grouped' => true,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'filters' => $filters,
            'requestedGroupBy' => $requestedGroupBy,
            'groupDimensions' => $groupDimensions,
            'dayOverDayHourTo' => $dayOverDayHourTo,
            'includeLimitState' => in_array('projectCode', $groupDimensions, true),
            'includeRecentHourlyAdMatchRates' => in_array('projectCode', $groupDimensions, true),
            'includeProjectAppInfos' => in_array('projectCode', $groupDimensions, true),
        ];
    }

    /**
     * Count grouped daily rows from the filtered base table without replaying metric joins.
     */
    private function countDailyGroups(array $definition): int
    {
        $groupDimensions = $definition['groupDimensions'] ?? [];
        if (empty($groupDimensions)) {
            return (clone $definition['baseQuery'])->count();
        }

        $query = clone $definition['baseQuery'];
        foreach ($groupDimensions as $dimension) {
            $column = $this->dailyDimensionColumn($dimension);
            $query->selectRaw($column . ' as ' . $this->dailyDimensionAlias($dimension))
                ->groupBy($column);
        }

        return (int) DB::query()->fromSub($query, 'daily_groups')->count();
    }

    /**
     * Apply daily report ordering, including computed cost aliases.
     */
    private function applyDailyOrder(
        Builder $query,
        string $orderKey,
        string $orderDirection,
        array $dimensionMap,
        array $metricMap,
        string $defaultColumn,
        bool $supportsTotalCostAlias
    ): void {
        if ($orderKey === 'totalCost') {
            if ($supportsTotalCostAlias) {
                $query->orderBy('total_cost', $orderDirection);
            } else {
                $query->orderByRaw('(ad_spend_cost + traffic_cost) ' . $orderDirection);
            }

            return;
        }

        $orderColumn = $dimensionMap[$orderKey] ?? $metricMap[$orderKey] ?? $defaultColumn;
        $query->orderBy($orderColumn, $orderDirection);
    }

    /**
     * Build project daily summary using the same spend-source semantics as the list query.
     */
    private function buildDailySummary(array $definition): array
    {
        $summaryQuery = clone $definition['baseQuery'];

        $row = $summaryQuery
            ->selectRaw('SUM(project_daily_aggregates.new_users) as new_users')
            ->selectRaw('SUM(project_daily_aggregates.report_new_users) as report_new_users')
            ->selectRaw('SUM(project_daily_aggregates.fb_new_users) as fb_new_users')
            ->selectRaw('SUM(project_daily_aggregates.dau_users) as dau_users')
            ->selectRaw('SUM(project_daily_aggregates.fb_dau_users) as fb_dau_users')
            ->selectRaw('SUM(project_daily_aggregates.ad_revenue) as ad_revenue')
            ->selectRaw('SUM(project_daily_aggregates.ad_requests) as ad_requests')
            ->selectRaw('SUM(project_daily_aggregates.ad_matched_requests) as ad_matched_requests')
            ->selectRaw('SUM(project_daily_aggregates.ad_impressions) as ad_impressions')
            ->selectRaw('SUM(project_daily_aggregates.ad_clicks) as ad_clicks')
            ->selectRaw('SUM(project_daily_aggregates.traffic_usage_mb) as traffic_usage_mb')
            ->selectRaw('SUM(project_daily_aggregates.traffic_cost) as traffic_cost')
            ->selectRaw('MAX(project_daily_aggregates.updated_at) as updated_at')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_impressions)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_revenue)/SUM(project_daily_aggregates.ad_impressions)*1000,6) END as ad_ecpm')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_impressions)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_clicks)/SUM(project_daily_aggregates.ad_impressions)*100,6) END as ad_ctr')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_requests)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_matched_requests)/SUM(project_daily_aggregates.ad_requests)*100,6) END as ad_match_rate')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_matched_requests)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_impressions)/SUM(project_daily_aggregates.ad_matched_requests)*100,6) END as ad_show_rate')
            ->first();
        $spendRow = $this->buildDailySummarySpendMetrics($definition);
        $newUsers = (int) ($row->new_users ?? 0);
        $adRevenue = (float) ($row->ad_revenue ?? 0);
        $trafficCost = (float) ($row->traffic_cost ?? 0);
        $adSpendCost = (float) ($spendRow->ad_spend_cost ?? 0);
        $adSpendClicks = (int) ($spendRow->ad_spend_clicks ?? 0);
        $adSpendImpressions = (int) ($spendRow->ad_spend_impressions ?? 0);
        $totalCost = $adSpendCost + $trafficCost;
        $profit = $adRevenue - $adSpendCost - $trafficCost;

        $adRevenueNow = $this->buildDailySummaryNowRevenue($definition);
        $adRevenueDiff = $adRevenueNow === null
            ? null
            : $adRevenueNow - (float) ($row->ad_revenue ?? 0);
        $dayOverDayMetrics = $this->buildDailySummaryDayOverDayMetrics($definition);

        return [
            'newUsers' => (int) ($row->new_users ?? 0),
            'reportNewUsers' => (int) ($row->report_new_users ?? 0),
            'fbNewUsers' => (int) ($row->fb_new_users ?? 0),
            'dauUsers' => (int) ($row->dau_users ?? 0),
            'fbDauUsers' => (int) ($row->fb_dau_users ?? 0),
            'adRevenue' => $this->formatDecimal($row->ad_revenue ?? null),
            'adRevenueNow' => $this->formatDecimal($adRevenueNow),
            'adRevenueDiff' => $this->formatDecimal($adRevenueDiff),
            'adRevenueDayOverDay' => $this->dayOverDayRatio(
                $dayOverDayMetrics['current']['ad_revenue'] ?? null,
                $dayOverDayMetrics['previous']['ad_revenue'] ?? null
            ),
            'adRequests' => (int) ($row->ad_requests ?? 0),
            'adMatchedRequests' => (int) ($row->ad_matched_requests ?? 0),
            'adImpressions' => (int) ($row->ad_impressions ?? 0),
            'adClicks' => (int) ($row->ad_clicks ?? 0),
            'adEcpm' => $this->formatDecimal($row->ad_ecpm ?? null),
            'adCtr' => $this->formatDecimal($row->ad_ctr ?? null),
            'adMatchRate' => $this->formatDecimal($row->ad_match_rate ?? null),
            'adShowRate' => $this->formatDecimal($row->ad_show_rate ?? null),
            'impressionsPerUser' => $this->ratio((float) ($row->ad_impressions ?? 0), (float) ($row->dau_users ?? 0)),
            'arpu' => $this->ratio((float) ($row->ad_revenue ?? 0), (float) ($row->dau_users ?? 0)),
            'adSpendCost' => $this->formatDecimal($adSpendCost),
            'adSpendCostDayOverDay' => $this->dayOverDayRatio(
                $dayOverDayMetrics['current']['ad_spend_cost'] ?? null,
                $dayOverDayMetrics['previous']['ad_spend_cost'] ?? null
            ),
            'adSpendCpi' => $this->formatDecimal($newUsers === 0 ? null : $adSpendCost / $newUsers),
            'adSpendCpc' => $this->formatDecimal($adSpendClicks === 0 ? null : $adSpendCost / $adSpendClicks),
            'adSpendCpm' => $this->formatDecimal($adSpendImpressions === 0 ? null : $adSpendCost * 1000 / $adSpendImpressions),
            'trafficUsageMb' => $this->formatDecimal($row->traffic_usage_mb ?? null),
            'trafficCost' => $this->formatDecimal($row->traffic_cost ?? null),
            'totalCost' => $this->formatDecimal($totalCost),
            'trafficCostRatio' => $this->formatDecimal($totalCost == 0.0 ? null : $trafficCost / $totalCost),
            'profit' => $this->formatDecimal($profit),
            'profitDayOverDay' => $this->dayOverDayRatio(
                $dayOverDayMetrics['current']['profit'] ?? null,
                $dayOverDayMetrics['previous']['profit'] ?? null
            ),
            'roi' => $this->formatDecimal($totalCost == 0.0 ? null : $adRevenue / $totalCost),
            'updatedAt' => $row->updated_at ?? null,
        ];
    }

    /**
     * Build summary comparison metrics from project_report_hourly up to the previous complete hour.
     */
    private function buildDailySummaryDayOverDayMetrics(array $definition): array
    {
        $hourTo = $definition['dayOverDayHourTo'] ?? null;
        if ($hourTo === null) {
            return [];
        }
        $hourTo = (int) $hourTo;

        $currentRow = $this->queryDailyHourlyDayOverDayMetrics($definition, false, false, false, false, [], $hourTo)->first();
        $previousRow = $this->queryDailyHourlyDayOverDayMetrics($definition, false, false, false, true, [], $hourTo)->first();

        if ($previousRow === null) {
            return [];
        }

        return [
            'current' => $this->extractDailyDayOverDayMetrics($currentRow),
            'previous' => $this->extractDailyDayOverDayMetrics($previousRow),
        ];
    }

    /**
     * Sum spend metrics only for daily aggregate dimension keys that survived report filters.
     */
    private function buildDailySummarySpendMetrics(array $definition): object
    {
        $dimensionQuery = (clone $definition['baseQuery'])
            ->selectRaw('project_daily_aggregates.report_date as report_date')
            ->selectRaw('project_daily_aggregates.project_code as project_code')
            ->selectRaw('project_daily_aggregates.country as country')
            ->distinct();
        $countryExpression = $this->normalizedCountrySql('spend.country');

        return DB::query()
            ->fromSub($dimensionQuery, 'daily_dims')
            ->leftJoin('ad_spend_platform_daily_reports as spend', function ($join) use ($countryExpression) {
                $join->on('spend.report_date', '=', 'daily_dims.report_date')
                    ->on('spend.project_code', '=', 'daily_dims.project_code')
                    ->whereRaw($countryExpression . ' = daily_dims.country');
            })
            ->selectRaw('COALESCE(SUM(spend.spend), 0) as ad_spend_cost')
            ->selectRaw('COALESCE(SUM(spend.clicks), 0) as ad_spend_clicks')
            ->selectRaw('COALESCE(SUM(spend.impressions), 0) as ad_spend_impressions')
            ->first();
    }

    /**
     * Build current revenue summary using the full filtered project/date range, not paginated rows.
     */
    private function buildDailySummaryNowRevenue(array $definition): ?float
    {
        $projectCodes = (clone $definition['baseQuery'])
            ->whereNotNull('project_daily_aggregates.project_code')
            ->where('project_daily_aggregates.project_code', '!=', '')
            ->distinct()
            ->pluck('project_daily_aggregates.project_code')
            ->map(static fn ($projectCode) => trim((string) $projectCode))
            ->filter(static fn ($projectCode) => $projectCode !== '')
            ->values()
            ->all();

        if (empty($projectCodes)) {
            return null;
        }

        $nowRevenue = $this->adRevenueService->getNowRevenueByProjectDate(
            $projectCodes,
            $definition['dateFrom'],
            $definition['dateTo']
        );

        if (empty($nowRevenue['byProject'])) {
            return null;
        }

        return array_sum(array_map(static fn ($amount) => (float) $amount, $nowRevenue['byProject']));
    }

    /**
     * Join aggregated spend metrics by day/project/country to the base daily query.
     */
    private function joinDailySpendMetrics(Builder $query, Builder $spendMetricsQuery): void
    {
        $query->leftJoinSub($spendMetricsQuery, 'spend_metrics', function ($join) {
            $join->on('spend_metrics.report_date', '=', 'project_daily_aggregates.report_date')
                ->on('spend_metrics.project_code', '=', 'project_daily_aggregates.project_code')
                ->on('spend_metrics.country', '=', 'project_daily_aggregates.country');
        });
    }

    /**
     * Join project metadata by project code for row-level report output.
     */
    private function joinProjectMetadata(Builder $query): void
    {
        $query->leftJoin('project_projects as report_projects', 'report_projects.project_code', '=', 'project_daily_aggregates.project_code');
    }

    /**
     * Join project metadata for hourly report rows.
     */
    private function joinHourlyProjectMetadata(Builder $query): void
    {
        $query->leftJoin('project_projects as report_projects', 'report_projects.project_code', '=', 'project_report_hourly.project_code');
    }

    /**
     * Attach current revenue and current-vs-report revenue diff to project report rows.
     */
    private function applyDailyNowRevenue($rows, array $definition): void
    {
        $projectCodes = $rows
            ->map(static fn ($row) => trim((string) ($row->project_code ?? '')))
            ->filter(static fn ($projectCode) => $projectCode !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($projectCodes)) {
            return;
        }

        $nowRevenue = $this->adRevenueService->getNowRevenueByProjectDate(
            $projectCodes,
            $definition['dateFrom'],
            $definition['dateTo']
        );

        foreach ($rows as $row) {
            $projectCode = trim((string) ($row->project_code ?? ''));
            if ($projectCode === '') {
                continue;
            }

            $nowAmount = null;
            $reportDate = isset($row->report_date) ? (string) $row->report_date : '';
            if ($reportDate !== '') {
                $nowAmount = $nowRevenue['byDate'][$this->makeProjectDateKey($projectCode, $reportDate)] ?? null;
            } else {
                $nowAmount = $nowRevenue['byProject'][$projectCode] ?? null;
            }

            $row->ad_revenue_now = $nowAmount;
            $row->ad_revenue_diff = $nowAmount === null
                ? null
                : $nowAmount - (float) ($row->ad_revenue ?? 0);
        }
    }

    /**
     * Attach previous-day ratios from hourly cumulative metrics without per-row queries.
     */
    private function applyDailyDayOverDayMetrics($rows, array $definition): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $hourTo = $definition['dayOverDayHourTo'] ?? null;
        if ($hourTo === null) {
            foreach ($rows as $row) {
                $this->attachDailyDayOverDayMetricValues($row, null, null);
            }

            return;
        }
        $hourTo = (int) $hourTo;

        $groupDimensions = $definition['groupDimensions'] ?? [];
        $grouped = (bool) ($definition['grouped'] ?? false);
        $includeDate = !$grouped || in_array('reportDate', $groupDimensions, true);
        $includeProject = !$grouped || in_array('projectCode', $groupDimensions, true);
        $includeCountry = !$grouped || in_array('country', $groupDimensions, true);
        $constraints = $this->buildDailyDayOverDayPageConstraints($rows, $includeDate, $includeProject, $includeCountry);

        $currentRows = $this->queryDailyHourlyDayOverDayMetrics(
            $definition,
            $includeDate,
            $includeProject,
            $includeCountry,
            false,
            $constraints,
            $hourTo
        );
        $previousRows = $this->queryDailyHourlyDayOverDayMetrics(
            $definition,
            $includeDate,
            $includeProject,
            $includeCountry,
            true,
            $constraints,
            $hourTo
        );

        $currentByKey = [];
        foreach ($currentRows as $currentRow) {
            $key = $this->makeDailyDayOverDayKey(
                $includeDate ? ($currentRow->report_date ?? null) : null,
                $includeProject ? ($currentRow->project_code ?? null) : null,
                $includeCountry ? ($currentRow->country ?? null) : null
            );
            $currentByKey[$key] = $currentRow;
        }

        $previousByKey = [];
        foreach ($previousRows as $previousRow) {
            $comparisonDate = $includeDate && isset($previousRow->report_date)
                ? Carbon::parse((string) $previousRow->report_date)->addDay()->toDateString()
                : null;

            $key = $this->makeDailyDayOverDayKey(
                $includeDate ? $comparisonDate : null,
                $includeProject ? ($previousRow->project_code ?? null) : null,
                $includeCountry ? ($previousRow->country ?? null) : null
            );
            $previousByKey[$key] = $previousRow;
        }

        foreach ($rows as $row) {
            $key = $this->makeDailyDayOverDayKey(
                $includeDate ? ($row->report_date ?? null) : null,
                $includeProject ? ($row->project_code ?? null) : null,
                $includeCountry ? ($row->country ?? null) : null
            );
            $this->attachDailyDayOverDayMetricValues($row, $currentByKey[$key] ?? null, $previousByKey[$key] ?? null);
        }
    }

    /**
     * Query current or previous-day hourly cumulative metrics using daily report filters.
     */
    private function queryDailyHourlyDayOverDayMetrics(
        array $definition,
        bool $includeDate,
        bool $includeProject,
        bool $includeCountry,
        bool $previous,
        array $constraints,
        int $hourTo
    ) {
        $dateFrom = Carbon::parse((string) $definition['dateFrom']);
        $dateTo = Carbon::parse((string) $definition['dateTo']);
        if ($previous) {
            $dateFrom->subDay();
            $dateTo->subDay();
        }

        $filters = is_array($definition['filters'] ?? null) ? $definition['filters'] : [];
        $countryExpression = $this->normalizedCountrySql('project_report_hourly.country');

        $query = DB::table('project_report_hourly')
            ->where('project_report_hourly.report_date', '>=', $dateFrom->toDateString())
            ->where('project_report_hourly.report_date', '<=', $dateTo->toDateString())
            ->whereBetween('project_report_hourly.hour', [0, $hourTo]);

        $dateConstraintKey = $previous ? 'previousDates' : 'currentDates';
        if (!empty($constraints[$dateConstraintKey])) {
            $query->whereIn('project_report_hourly.report_date', $constraints[$dateConstraintKey]);
        }
        if (!empty($constraints['projectCodes'])) {
            $query->whereIn('project_report_hourly.project_code', $constraints['projectCodes']);
        }
        if (!empty($constraints['countries'])) {
            $query->whereIn(DB::raw($countryExpression), $constraints['countries']);
        }

        $this->applyProjectCodeCountryFilters($query, 'project_report_hourly.project_code', DB::raw($countryExpression), $filters);
        $this->applyProjectAdStatusFilter($query, 'project_report_hourly.project_code', $filters);
        $this->applyProjectAppPlatformFilter($query, 'project_report_hourly.project_code', $filters);
        $this->applyProjectDepartmentFilter($query, 'project_report_hourly.project_code', $filters);

        if ($includeDate) {
            $query->selectRaw('project_report_hourly.report_date as report_date')
                ->groupBy('project_report_hourly.report_date');
        }
        if ($includeProject) {
            $query->selectRaw('project_report_hourly.project_code as project_code')
                ->groupBy('project_report_hourly.project_code');
        }
        if ($includeCountry) {
            $query->selectRaw($countryExpression . ' as country')
                ->groupBy(DB::raw($countryExpression));
        }

        return $query
            ->selectRaw('COALESCE(SUM(project_report_hourly.ad_revenue), 0) as ad_revenue')
            ->selectRaw('COALESCE(SUM(project_report_hourly.ad_spend_cost), 0) as ad_spend_cost')
            ->selectRaw('COALESCE(SUM(project_report_hourly.profit), 0) as profit')
            ->get();
    }

    /**
     * Limit hourly comparison lookups to dimensions visible on the current page.
     */
    private function buildDailyDayOverDayPageConstraints($rows, bool $includeDate, bool $includeProject, bool $includeCountry): array
    {
        $constraints = [
            'currentDates' => [],
            'previousDates' => [],
            'projectCodes' => [],
            'countries' => [],
        ];

        foreach ($rows as $row) {
            if ($includeDate && isset($row->report_date)) {
                $currentDate = Carbon::parse((string) $row->report_date);
                $constraints['currentDates'][] = $currentDate->toDateString();
                $constraints['previousDates'][] = $currentDate->copy()->subDay()->toDateString();
            }
            if ($includeProject) {
                $projectCode = trim((string) ($row->project_code ?? ''));
                if ($projectCode !== '') {
                    $constraints['projectCodes'][] = $projectCode;
                }
            }
            if ($includeCountry) {
                $constraints['countries'][] = $this->normalizeCountry((string) ($row->country ?? ''));
            }
        }

        return [
            'currentDates' => array_values(array_unique($constraints['currentDates'])),
            'previousDates' => array_values(array_unique($constraints['previousDates'])),
            'projectCodes' => array_values(array_unique($constraints['projectCodes'])),
            'countries' => array_values(array_unique($constraints['countries'])),
        ];
    }

    /**
     * Extract the three metrics used by day-over-day calculations.
     */
    private function extractDailyDayOverDayMetrics(?object $row): array
    {
        return [
            'ad_revenue' => $row === null ? 0.0 : (float) ($row->ad_revenue ?? 0),
            'ad_spend_cost' => $row === null ? 0.0 : (float) ($row->ad_spend_cost ?? 0),
            'profit' => $row === null ? 0.0 : (float) ($row->profit ?? 0),
        ];
    }

    /**
     * Attach formatted day-over-day ratio values to one report row.
     */
    private function attachDailyDayOverDayMetricValues(object $row, ?object $currentRow, ?object $previousRow): void
    {
        $current = $this->extractDailyDayOverDayMetrics($currentRow);
        $previous = $previousRow === null ? null : $this->extractDailyDayOverDayMetrics($previousRow);

        $row->ad_revenue_day_over_day = $this->calculateDayOverDayRatio(
            $current['ad_revenue'],
            $previous['ad_revenue'] ?? null
        );
        $row->ad_spend_cost_day_over_day = $this->calculateDayOverDayRatio(
            $current['ad_spend_cost'],
            $previous['ad_spend_cost'] ?? null
        );
        $row->profit_day_over_day = $this->calculateDayOverDayRatio(
            $current['profit'],
            $previous['profit'] ?? null
        );
    }

    /**
     * Build a compact lookup key for current rows and their previous-day counterparts.
     */
    private function makeDailyDayOverDayKey($reportDate, $projectCode, $country): string
    {
        return implode("\t", [
            trim((string) ($reportDate ?? '')),
            trim((string) ($projectCode ?? '')),
            $this->normalizeCountry((string) ($country ?? '')),
        ]);
    }

    /**
     * Attach top revenue countries for each returned report row without changing the main list query.
     */
    private function applyTopRevenueCountries($rows, string $scope, array $context): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $table = $scope === 'hourly' ? 'project_report_hourly' : 'project_daily_aggregates';
        $dateFrom = (string) ($context['dateFrom'] ?? now()->subDays(1)->toDateString());
        $dateTo = (string) ($context['dateTo'] ?? now()->toDateString());
        $filters = is_array($context['filters'] ?? null) ? $context['filters'] : [];

        $hasReportDate = $this->rowsContainProperty($rows, 'report_date');
        $hasProjectCode = $this->rowsContainProperty($rows, 'project_code');
        $hasCountry = $this->rowsContainProperty($rows, 'country');
        $hasHour = $scope === 'hourly' && $this->rowsContainProperty($rows, 'hour');

        if ($scope === 'daily' && $hasCountry) {
            $this->applyCurrentRowTopRevenueCountry($rows);

            return;
        }

        $reportDates = $hasReportDate ? $this->collectRowValues($rows, 'report_date') : [];
        $projectCodes = $hasProjectCode ? $this->collectRowValues($rows, 'project_code') : [];
        $rowCountries = $hasCountry
            ? $this->collectRowValues($rows, 'country', static fn ($country) => strtoupper((string) $country))
            : [];
        $hours = $hasHour ? $this->collectRowValues($rows, 'hour', static fn ($hour) => (int) $hour) : [];

        $countryExpression = $this->normalizedCountrySql($table . '.country');
        $query = DB::table($table)
            ->where($table . '.report_date', '>=', $dateFrom)
            ->where($table . '.report_date', '<=', $dateTo);

        if (!empty($reportDates)) {
            $query->whereIn($table . '.report_date', $reportDates);
        }

        if ($scope === 'hourly') {
            if (!empty($hours)) {
                $query->whereIn($table . '.hour', $hours);
            } else {
                if (($context['hourFrom'] ?? null) !== null) {
                    $query->where($table . '.hour', '>=', (int) $context['hourFrom']);
                }
                if (($context['hourTo'] ?? null) !== null) {
                    $query->where($table . '.hour', '<=', (int) $context['hourTo']);
                }
            }
        }

        $effectiveFilters = $filters;
        if (!empty($projectCodes)) {
            $effectiveFilters['projectCodes'] = $projectCodes;
        }
        if (!empty($rowCountries)) {
            $effectiveFilters['countries'] = $rowCountries;
        }
        $this->applyProjectCodeCountryFilters($query, $table . '.project_code', DB::raw($countryExpression), $effectiveFilters);

        $this->applyProjectAdStatusFilter($query, $table . '.project_code', $filters);
        $this->applyProjectAppPlatformFilter($query, $table . '.project_code', $filters);
        $this->applyProjectDepartmentFilter($query, $table . '.project_code', $filters);

        $query->selectRaw($countryExpression . ' as country')
            ->selectRaw('SUM(' . $table . '.ad_revenue) as ad_revenue');

        if ($hasReportDate) {
            $query->selectRaw($table . '.report_date as report_date')
                ->groupBy($table . '.report_date');
        }
        if ($hasProjectCode) {
            $query->selectRaw($table . '.project_code as project_code')
                ->groupBy($table . '.project_code');
        }
        if ($hasHour) {
            $query->selectRaw($table . '.hour as hour')
                ->groupBy($table . '.hour');
        }

        $aggregateRows = $query
            ->groupBy(DB::raw($countryExpression))
            ->get();

        $countryBuckets = [];
        foreach ($aggregateRows as $aggregateRow) {
            $key = $this->makeTopRevenueCountryScopeKey(
                $hasReportDate ? ($aggregateRow->report_date ?? null) : null,
                $hasProjectCode ? ($aggregateRow->project_code ?? null) : null,
                $hasCountry ? ($aggregateRow->country ?? null) : null,
                $hasHour ? ($aggregateRow->hour ?? null) : null
            );
            $country = (string) ($aggregateRow->country ?: 'XX');
            $amount = (float) ($aggregateRow->ad_revenue ?? 0);

            $countryBuckets[$key]['total'] = ($countryBuckets[$key]['total'] ?? 0.0) + $amount;
            $countryBuckets[$key]['countries'][$country] = ($countryBuckets[$key]['countries'][$country] ?? 0.0) + $amount;
        }

        $formattedBuckets = [];
        foreach ($countryBuckets as $key => $bucket) {
            $formattedBuckets[$key] = $this->formatTopRevenueCountries(
                $bucket['countries'] ?? [],
                (float) ($bucket['total'] ?? 0)
            );
        }

        foreach ($rows as $row) {
            $key = $this->makeTopRevenueCountryScopeKey(
                $hasReportDate ? ($row->report_date ?? null) : null,
                $hasProjectCode ? ($row->project_code ?? null) : null,
                $hasCountry ? ($row->country ?? null) : null,
                $hasHour ? ($row->hour ?? null) : null
            );
            $row->top_revenue_countries = $formattedBuckets[$key] ?? [];
        }
    }

    /**
     * Use the current country row as its own Top country to avoid redundant country re-aggregation.
     */
    private function applyCurrentRowTopRevenueCountry($rows): void
    {
        foreach ($rows as $row) {
            $country = (string) (($row->country ?? '') ?: 'XX');
            $adRevenue = (float) ($row->ad_revenue ?? 0);
            $row->top_revenue_countries = $this->formatTopRevenueCountries(
                [strtoupper($country) => $adRevenue],
                $adRevenue
            );
        }
    }

    /**
     * Check whether any returned row contains a selected dimension property.
     */
    private function rowsContainProperty($rows, string $property): bool
    {
        foreach ($rows as $row) {
            if (property_exists($row, $property)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collect unique non-empty dimension values from the current page rows.
     */
    private function collectRowValues($rows, string $property, ?callable $normalizer = null): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (!property_exists($row, $property)) {
                continue;
            }

            $value = $row->{$property};
            if ($value === null || $value === '') {
                continue;
            }

            $value = $normalizer ? $normalizer($value) : (string) $value;
            $values[(string) $value] = $value;
        }

        return array_values($values);
    }

    /**
     * Build a stable key for row-level top-country lookup scopes.
     */
    private function makeTopRevenueCountryScopeKey($reportDate, $projectCode, $country, $hour): string
    {
        return implode("\t", [
            $reportDate === null || $reportDate === '' ? '*' : (string) $reportDate,
            $projectCode === null || $projectCode === '' ? '*' : (string) $projectCode,
            $country === null || $country === '' ? '*' : strtoupper((string) $country),
            $hour === null || $hour === '' ? '*' : (string) (int) $hour,
        ]);
    }

    /**
     * Format the top three revenue countries and each country's revenue ratio.
     */
    private function formatTopRevenueCountries(array $countries, float $totalRevenue): array
    {
        if ($totalRevenue <= 0.0 || empty($countries)) {
            return [];
        }

        arsort($countries, SORT_NUMERIC);
        $result = [];
        foreach (array_slice($countries, 0, 3, true) as $country => $amount) {
            $amount = (float) $amount;
            $result[] = [
                'country' => (string) $country,
                'adRevenue' => $this->formatDecimal($amount),
                'ratio' => $this->formatDecimal($amount / $totalRevenue),
            ];
        }

        return $result;
    }

    /**
     * Apply cached previous-hour limit state to grouped project rows.
     */
    private function applyDailyLimitState($rows): void
    {
        $limitMetrics = $this->loadPreviousHourLimitMetrics();

        foreach ($rows as $row) {
            $projectCode = trim((string) ($row->project_code ?? ''));
            if ($projectCode === '') {
                continue;
            }

            $metrics = $limitMetrics[$projectCode] ?? [
                'hourly_ad_requests' => 0,
                'matched_requests' => 0,
                'hourly_new_users' => 0,
                'daily_ad_requests' => 0,
                'daily_new_users' => 0,
            ];
            $hourlyAdRequests = (int) ($metrics['hourly_ad_requests'] ?? 0);
            $matchedRequests = (int) ($metrics['matched_requests'] ?? 0);
            $hourlyNewUsers = (int) ($metrics['hourly_new_users'] ?? 0);
            $dailyNewUsers = (int) ($metrics['daily_new_users'] ?? 0);
            $row->hourly_status = $this->buildHourlyLimitStatus($hourlyAdRequests, $hourlyNewUsers, $dailyNewUsers);

            if ($hourlyAdRequests === 0) {
                $row->is_limited = 0;
            } else {
                $row->is_limited = ($matchedRequests / $hourlyAdRequests) < 0.7 ? 1 : 0;
            }
        }
    }

    /**
     * Load previous-hour project ad request metrics with a short cache TTL.
     */
    private function loadPreviousHourLimitMetrics(): array
    {
        $previousHour = now('Asia/Shanghai')->startOfHour()->subHour();
        $previousHourDate = $previousHour->toDateString();
        $previousHourValue = (int) $previousHour->format('G');
        $previousHourString = $previousHour->toDateTimeString();
        $cacheKey = 'project_report:is_limited_metrics:v5:' . $previousHourString;

        return Cache::remember($cacheKey, 60, function () use ($previousHourString, $previousHourDate, $previousHourValue) {
            $dailyRows = DB::table('project_daily_aggregates')
                ->where('report_date', '=', $previousHourDate)
                ->whereNotNull('project_code')
                ->where('project_code', '!=', '')
                ->selectRaw('project_code')
                ->selectRaw('SUM(ad_requests) as daily_ad_requests')
                ->selectRaw('SUM(new_users) as daily_new_users')
                ->groupBy('project_code')
                ->get();

            $hourlyRows = DB::table('project_report_hourly')
                ->where('report_date', '=', $previousHourDate)
                ->where('hour', '=', $previousHourValue)
                ->whereNotNull('project_code')
                ->where('project_code', '!=', '')
                ->selectRaw('project_code')
                ->selectRaw('SUM(new_users) as hourly_new_users')
                ->groupBy('project_code')
                ->get();

            $rows = DB::table('project_ad_platform_accounts as papa')
                ->join('project_projects as p', 'p.project_code', '=', 'papa.project_code')
                ->leftJoin('ad_revenue_hourly as arh', function ($join) use ($previousHourString) {
                    $join->on('arh.account_id', '=', 'papa.ad_platform_account_id')
                        ->where('arh.report_hour', '=', $previousHourString);
                })
                ->where('papa.enabled', '=', 1)
                ->whereNotNull('papa.project_code')
                ->where('papa.project_code', '!=', '')
                ->selectRaw('p.project_code as project_code')
                ->selectRaw('COALESCE(SUM(arh.ad_requests), 0) as ad_requests')
                ->selectRaw('COALESCE(SUM(arh.matched_requests), 0) as matched_requests')
                ->groupBy('p.project_code')
                ->get();

            $result = [];
            foreach ($rows as $row) {
                $projectCode = trim((string) ($row->project_code ?? ''));
                if ($projectCode === '') {
                    continue;
                }

                $result[$projectCode] = [
                    'hourly_ad_requests' => (int) ($row->ad_requests ?? 0),
                    'matched_requests' => (int) ($row->matched_requests ?? 0),
                    'hourly_new_users' => 0,
                    'daily_ad_requests' => 0,
                    'daily_new_users' => 0,
                ];
            }

            foreach ($hourlyRows as $row) {
                $projectCode = trim((string) ($row->project_code ?? ''));
                if ($projectCode === '') {
                    continue;
                }

                $result[$projectCode] ??= [
                    'hourly_ad_requests' => 0,
                    'matched_requests' => 0,
                    'hourly_new_users' => 0,
                    'daily_ad_requests' => 0,
                    'daily_new_users' => 0,
                ];
                $result[$projectCode]['hourly_new_users'] = (int) ($row->hourly_new_users ?? 0);
            }

            foreach ($dailyRows as $row) {
                $projectCode = trim((string) ($row->project_code ?? ''));
                if ($projectCode === '') {
                    continue;
                }

                $result[$projectCode] ??= [
                    'hourly_ad_requests' => 0,
                    'matched_requests' => 0,
                    'hourly_new_users' => 0,
                    'daily_ad_requests' => 0,
                    'daily_new_users' => 0,
                ];
                $result[$projectCode]['daily_ad_requests'] = (int) ($row->daily_ad_requests ?? 0);
                $result[$projectCode]['daily_new_users'] = (int) ($row->daily_new_users ?? 0);
            }

            return $result;
        });
    }

    /**
     * Build hourly status bit flags when previous-hour ad requests are zero.
     */
    private function buildHourlyLimitStatus(int $hourlyAdRequests, int $hourlyNewUsers, int $dailyNewUsers): int
    {
        if ($hourlyAdRequests > 0) {
            return 0;
        }

        $status = 1;
        if ($hourlyNewUsers === 0) {
            $status |= 2;
        }
        if ($dailyNewUsers === 0) {
            $status |= 4;
        }

        return $status;
    }

    /**
     * Attach recent project-level hourly ad match rates to project grouped daily rows.
     */
    private function applyRecentHourlyAdMatchRates($rows): void
    {
        $projectCodes = [];
        foreach ($rows as $row) {
            $projectCode = trim((string) ($row->project_code ?? ''));
            if ($projectCode !== '') {
                $projectCodes[] = $projectCode;
            }
        }

        $ratesByProject = $this->loadRecentHourlyAdMatchRates($projectCodes);

        foreach ($rows as $row) {
            $projectCode = trim((string) ($row->project_code ?? ''));
            if ($projectCode === '') {
                continue;
            }

            $row->recent_hourly_ad_match_rates = $ratesByProject[$projectCode] ?? [];
        }
    }

    /**
     * Load the latest 12 Asia/Shanghai hour buckets from project_report_hourly by project.
     */
    private function loadRecentHourlyAdMatchRates(array $projectCodes): array
    {
        $projectCodes = $this->normalizeStringList($projectCodes);
        sort($projectCodes, SORT_STRING);
        if (empty($projectCodes)) {
            return [];
        }

        $endHour = now('Asia/Shanghai')->startOfHour();
        $startHour = (clone $endHour)->subHours(11);
        $cacheKey = sprintf(
            'project_report:recent_hourly_ad_match_rates:v1:%s:%s:%s',
            $startHour->format('YmdH'),
            $endHour->format('YmdH'),
            md5(implode('|', $projectCodes))
        );

        return Cache::remember($cacheKey, self::RECENT_HOURLY_AD_MATCH_RATE_CACHE_TTL, function () use ($projectCodes, $startHour, $endHour) {
            $startDate = $startHour->toDateString();
            $endDate = $endHour->toDateString();
            $startHourValue = (int) $startHour->format('G');
            $endHourValue = (int) $endHour->format('G');

            $rows = DB::table('project_report_hourly')
                ->whereIn('project_code', $projectCodes)
                ->where(function (Builder $query) use ($startDate, $endDate, $startHourValue, $endHourValue) {
                    if ($startDate === $endDate) {
                        $query->where('report_date', '=', $startDate)
                            ->whereBetween('hour', [$startHourValue, $endHourValue]);

                        return;
                    }

                    // Keep predicates index-friendly instead of wrapping report_date/hour in SQL functions.
                    $query->where(function (Builder $subQuery) use ($startDate, $startHourValue) {
                        $subQuery->where('report_date', '=', $startDate)
                            ->where('hour', '>=', $startHourValue);
                    })->orWhere(function (Builder $subQuery) use ($startDate, $endDate) {
                        $subQuery->where('report_date', '>', $startDate)
                            ->where('report_date', '<', $endDate);
                    })->orWhere(function (Builder $subQuery) use ($endDate, $endHourValue) {
                        $subQuery->where('report_date', '=', $endDate)
                            ->where('hour', '<=', $endHourValue);
                    });
                })
                ->selectRaw('project_code')
                ->selectRaw('report_date')
                ->selectRaw('hour')
                ->selectRaw('SUM(ad_requests) as ad_requests')
                ->selectRaw('SUM(ad_matched_requests) as ad_matched_requests')
                ->groupBy('project_code', 'report_date', 'hour')
                ->orderBy('report_date')
                ->orderBy('hour')
                ->get();

            $result = array_fill_keys($projectCodes, []);
            foreach ($rows as $row) {
                $projectCode = trim((string) ($row->project_code ?? ''));
                if ($projectCode === '') {
                    continue;
                }

                $adRequests = (int) ($row->ad_requests ?? 0);
                $adMatchedRequests = (int) ($row->ad_matched_requests ?? 0);
                $reportDate = (string) ($row->report_date ?? '');
                $hour = (int) ($row->hour ?? 0);

                $result[$projectCode][] = [
                    'reportDate' => $reportDate,
                    'hour' => $hour,
                    'hourStart' => sprintf('%s %02d:00:00', $reportDate, $hour),
                    'adRequests' => $adRequests,
                    'adMatchedRequests' => $adMatchedRequests,
                    'adMatchRate' => $adRequests === 0
                        ? null
                        : $this->formatDecimal($adMatchedRequests / $adRequests * 100),
                ];
            }

            return $result;
        });
    }

    /**
     * Attach application information to project grouped report rows.
     */
    private function applyProjectAppInfos($rows): void
    {
        $projectCodes = [];
        foreach ($rows as $row) {
            $projectCode = trim((string) ($row->project_code ?? ''));
            if ($projectCode !== '') {
                $projectCodes[] = $projectCode;
            }
        }

        $projectCodes = $this->normalizeStringList($projectCodes);
        if (empty($projectCodes)) {
            return;
        }

        $appIdsByProject = ProjectUserAppMap::query()
            ->whereIn('project_code', $projectCodes)
            ->where('enabled', 1)
            ->get(['project_code', 'app_id'])
            ->groupBy('project_code')
            ->map(fn ($items) => $items
                ->pluck('app_id')
                ->map(fn ($appId) => trim((string) $appId))
                ->filter(fn ($appId) => $appId !== '')
                ->unique()
                ->values());

        $allAppIds = $appIdsByProject
            ->flatMap(fn ($appIds) => $appIds)
            ->unique()
            ->values();

        $appInfosByAppId = $allAppIds->isEmpty()
            ? collect()
            : ProjectAppInfo::query()
                ->whereIn('app_id', $allAppIds->all())
                ->orderByDesc('enabled')
                ->orderBy('app_id')
                ->get()
                ->keyBy('app_id');

        foreach ($rows as $row) {
            $projectCode = trim((string) ($row->project_code ?? ''));
            if ($projectCode === '') {
                continue;
            }

            $row->app_infos = ($appIdsByProject[$projectCode] ?? collect())
                ->map(fn ($appId) => $appInfosByAppId[$appId] ?? null)
                ->filter()
                ->map(fn ($item) => ProjectAppInfoService::format($item))
                ->values()
                ->all();
        }
    }

    /**
     * Select project metadata fields for non-grouped report rows.
     */
    private function selectProjectMetadata(Builder $query): void
    {
        foreach (self::PROJECT_METADATA_COLUMNS as $column => $alias) {
            $query->addSelect("report_projects.{$column} as project_{$column}");
        }
    }

    /**
     * Select project metadata fields for grouped rows that include projectCode.
     */
    private function selectGroupedProjectMetadata(Builder $query): void
    {
        foreach (self::PROJECT_METADATA_COLUMNS as $column => $alias) {
            $query->selectRaw("MAX(report_projects.{$column}) as project_{$column}");
        }
    }

    /**
     * Aggregate spend metrics directly from the ad spend daily report table.
     */
    private function buildDailySpendMetricSubquery(string $dateFrom, string $dateTo, array $filters): Builder
    {
        $countryExpression = $this->normalizedCountrySql('country');

        $query = DB::table('ad_spend_platform_daily_reports')
            ->where('report_date', '>=', $dateFrom)
            ->where('report_date', '<=', $dateTo);

        $this->applyProjectCodeCountryFilters($query, 'project_code', DB::raw($countryExpression), $filters);

        return $query
            ->selectRaw('report_date')
            ->selectRaw('project_code')
            ->selectRaw($countryExpression . ' as country')
            ->selectRaw('SUM(spend) as ad_spend_cost')
            ->selectRaw('SUM(clicks) as ad_spend_clicks')
            ->selectRaw('SUM(impressions) as ad_spend_impressions')
            ->groupBy('report_date', 'project_code', DB::raw($countryExpression));
    }

    /**
     * Filter report rows by the ad delivery status stored on project_projects.
     */
    private function applyProjectAdStatusFilter(Builder $query, string $projectCodeColumn, array $filters): void
    {
        $adStatuses = $this->normalizeStringList($filters['adStatuses'] ?? null);
        if (empty($adStatuses)) {
            return;
        }

        $query->whereExists(function (Builder $subQuery) use ($projectCodeColumn, $adStatuses) {
            $subQuery->selectRaw('1')
                ->from('project_projects')
                ->whereColumn('project_projects.project_code', $projectCodeColumn)
                ->whereIn('project_projects.ad_status', $adStatuses);
        });
    }

    /**
     * Filter report rows by the application platform stored on project_projects.
     */
    private function applyProjectAppPlatformFilter(Builder $query, string $projectCodeColumn, array $filters): void
    {
        $appPlatforms = $this->normalizeStringList($filters['appPlatforms'] ?? null);
        if (empty($appPlatforms)) {
            return;
        }

        $query->whereExists(function (Builder $subQuery) use ($projectCodeColumn, $appPlatforms) {
            $subQuery->selectRaw('1')
                ->from('project_projects')
                ->whereColumn('project_projects.project_code', $projectCodeColumn)
                ->whereIn('project_projects.app_platform', $appPlatforms);
        });
    }

    /**
     * Filter report rows by the department stored on project_projects.
     */
    private function applyProjectDepartmentFilter(Builder $query, string $projectCodeColumn, array $filters): void
    {
        $departments = $this->normalizeStringList($filters['departments'] ?? null);
        if (empty($departments)) {
            return;
        }

        $query->whereExists(function (Builder $subQuery) use ($projectCodeColumn, $departments) {
            $subQuery->selectRaw('1')
                ->from('project_projects')
                ->whereColumn('project_projects.project_code', $projectCodeColumn)
                ->whereIn('project_projects.department', $departments);
        });
    }

    /**
     * Apply include and exclude filters for project code and country dimensions.
     */
    private function applyProjectCodeCountryFilters(Builder $query, string $projectCodeColumn, $countryColumn, array $filters): void
    {
        $projectCodes = $this->normalizeStringList($filters['projectCodes'] ?? null);
        if (!empty($projectCodes)) {
            $query->whereIn($projectCodeColumn, $projectCodes);
        }

        $countries = array_map(
            static fn ($country) => strtoupper($country),
            $this->normalizeStringList($filters['countries'] ?? null)
        );
        if (!empty($countries)) {
            $query->whereIn($countryColumn, $countries);
        }

        $exclude = is_array($filters['exclude'] ?? null) ? $filters['exclude'] : [];
        $excludeProjectCodes = $this->normalizeStringList($exclude['projectCodes'] ?? null);
        if (!empty($excludeProjectCodes)) {
            $query->whereNotIn($projectCodeColumn, $excludeProjectCodes);
        }

        $excludeCountries = array_map(
            static fn ($country) => strtoupper($country),
            $this->normalizeStringList($exclude['countries'] ?? null)
        );
        if (!empty($excludeCountries)) {
            $query->whereNotIn($countryColumn, $excludeCountries);
        }
    }

    /**
     * Normalize optional string-list filters and remove blank values.
     */
    private function normalizeStringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            $value
        ), static fn ($item) => $item !== '')));
    }

    /**
     * Normalize country fields to the same semantics as project_daily_aggregates.
     */
    private function normalizedCountrySql(string $column): string
    {
        return "CASE WHEN TRIM(COALESCE({$column}, '')) = '' THEN 'XX' ELSE UPPER(COALESCE({$column}, '')) END";
    }

    /**
     * Normalize country values for in-memory comparison keys.
     */
    private function normalizeCountry(?string $country): string
    {
        $value = strtoupper(trim((string) ($country ?? '')));

        return $value === '' ? 'XX' : $value;
    }

    /**
     * Get the stable select alias for a grouped daily dimension.
     */
    private function dailyDimensionAlias(string $dimension): string
    {
        return match ($dimension) {
            'reportDate' => 'report_date',
            'projectCode' => 'project_code',
            'country' => 'country',
            default => $dimension,
        };
    }

    /**
     * Resolve the project_daily_aggregates column for a grouped daily dimension.
     */
    private function dailyDimensionColumn(string $dimension): string
    {
        return match ($dimension) {
            'reportDate' => 'project_daily_aggregates.report_date',
            'projectCode' => 'project_daily_aggregates.project_code',
            'country' => 'project_daily_aggregates.country',
            default => $dimension,
        };
    }

    /**
     * Get the stable select alias for a grouped hourly dimension.
     */
    private function hourlyDimensionAlias(string $dimension): string
    {
        return match ($dimension) {
            'reportDate' => 'report_date',
            'projectCode' => 'project_code',
            'country' => 'country',
            'hour' => 'hour',
            default => $dimension,
        };
    }

    private function formatDailyRow(object $row): array
    {
        $data = [
            'id' => isset($row->id) ? (int) $row->id : null,
            'reportDate' => isset($row->report_date) ? (string) $row->report_date : null,
            'projectCode' => $row->project_code ?? null,
            'country' => $row->country ?? null,
            'newUsers' => (int) ($row->new_users ?? 0),
            'reportNewUsers' => (int) ($row->report_new_users ?? 0),
            'fbNewUsers' => (int) ($row->fb_new_users ?? 0),
            'dauUsers' => (int) ($row->dau_users ?? 0),
            'fbDauUsers' => (int) ($row->fb_dau_users ?? 0),
            'adRevenue' => $this->formatDecimal($row->ad_revenue ?? null),
            'adRequests' => (int) ($row->ad_requests ?? 0),
            'adMatchedRequests' => (int) ($row->ad_matched_requests ?? 0),
            'adImpressions' => (int) ($row->ad_impressions ?? 0),
            'adClicks' => (int) ($row->ad_clicks ?? 0),
            'adEcpm' => $this->formatDecimal($row->ad_ecpm ?? null),
            'adCtr' => $this->formatDecimal($row->ad_ctr ?? null),
            'adMatchRate' => $this->formatDecimal($row->ad_match_rate ?? null),
            'adShowRate' => $this->formatDecimal($row->ad_show_rate ?? null),
            'impressionsPerUser' => $this->ratio((float) ($row->ad_impressions ?? 0), (float) ($row->dau_users ?? 0)),
            'arpu' => $this->ratio((float) ($row->ad_revenue ?? 0), (float) ($row->dau_users ?? 0)),
            'adSpendCost' => $this->formatDecimal($row->ad_spend_cost ?? null),
            'adSpendCpi' => $this->formatDecimal($row->ad_spend_cpi ?? null),
            'adSpendCpc' => $this->formatDecimal($row->ad_spend_cpc ?? null),
            'adSpendCpm' => $this->formatDecimal($row->ad_spend_cpm ?? null),
            'trafficUsageMb' => $this->formatDecimal($row->traffic_usage_mb ?? null),
            'trafficCost' => $this->formatDecimal($row->traffic_cost ?? null),
            'totalCost' => $this->formatDecimal($row->total_cost ?? (($row->ad_spend_cost ?? 0) + ($row->traffic_cost ?? 0))),
            'trafficCostRatio' => $this->formatDecimal($row->traffic_cost_ratio ?? null),
            'profit' => $this->formatDecimal($row->profit ?? null),
            'roi' => $this->formatDecimal($row->roi ?? null),
            'updatedAt' => $row->updated_at ?? null,
        ];

        if (!empty($data['projectCode'])) {
            $data = array_merge($data, $this->formatProjectMetadata($row));
        }

        if (property_exists($row, 'is_limited')) {
            $data['isLimited'] = $row->is_limited === null ? null : (bool) (int) $row->is_limited;
        }

        if (property_exists($row, 'hourly_status')) {
            $data['hourly_status'] = (int) $row->hourly_status;
        }

        if (property_exists($row, 'ad_revenue_now')) {
            $data['adRevenueNow'] = $this->formatDecimal($row->ad_revenue_now);
            $data['adRevenueDiff'] = $this->formatDecimal($row->ad_revenue_diff ?? null);
        }

        if (property_exists($row, 'ad_revenue_day_over_day')) {
            $data['adRevenueDayOverDay'] = $this->formatDecimal($row->ad_revenue_day_over_day);
            $data['adSpendCostDayOverDay'] = $this->formatDecimal($row->ad_spend_cost_day_over_day ?? null);
            $data['profitDayOverDay'] = $this->formatDecimal($row->profit_day_over_day ?? null);
        }

        if (property_exists($row, 'top_revenue_countries')) {
            $data['topRevenueCountries'] = $row->top_revenue_countries;
        }

        if (property_exists($row, 'recent_hourly_ad_match_rates')) {
            $data['recentHourlyAdMatchRates'] = $row->recent_hourly_ad_match_rates;
        }

        if (property_exists($row, 'app_infos')) {
            $data['appInfos'] = $row->app_infos;
        }

        return $data;
    }

    /**
     * Format hourly rows with the same metric fields as the daily report plus hour.
     */
    private function formatHourlyRow(object $row): array
    {
        $data = $this->formatDailyRow($row);
        $data['hour'] = isset($row->hour) ? (int) $row->hour : null;
        $data['isLimited'] = $this->isHourlyLimitedByMatchRate($row->ad_match_rate ?? null);

        return $data;
    }

    /**
     * Determine whether an hourly report row is limited by its own ad match rate.
     */
    private function isHourlyLimitedByMatchRate($adMatchRate): ?bool
    {
        if ($adMatchRate === null || $adMatchRate === '') {
            return null;
        }

        return (float) $adMatchRate < 70.0;
    }

    /**
     * Format project metadata fields from report query aliases.
     */
    private function formatProjectMetadata(object $row): array
    {
        $metadata = [];
        foreach (self::PROJECT_METADATA_COLUMNS as $column => $alias) {
            $property = 'project_' . $column;
            $metadata[$alias] = $row->{$property} ?? null;
        }

        return $metadata;
    }

    private function formatDailyCsvRow(array $row): array
    {
        return [
            $row['reportDate'] ?? '',
            $row['projectCode'] ?? '',
            $row['country'] ?? '',
            $row['newUsers'] ?? 0,
            $row['reportNewUsers'] ?? 0,
            $row['fbNewUsers'] ?? 0,
            $row['dauUsers'] ?? 0,
            $row['fbDauUsers'] ?? 0,
            $row['adRevenue'] ?? '',
            $row['adRequests'] ?? 0,
            $row['adMatchedRequests'] ?? 0,
            $row['adImpressions'] ?? 0,
            $row['adClicks'] ?? 0,
            $row['adEcpm'] ?? '',
            $row['adCtr'] ?? '',
            $row['adMatchRate'] ?? '',
            $row['adShowRate'] ?? '',
            $row['impressionsPerUser'] ?? '',
            $row['arpu'] ?? '',
            $row['adSpendCost'] ?? '',
            $row['adSpendCpi'] ?? '',
            $row['adSpendCpc'] ?? '',
            $row['adSpendCpm'] ?? '',
            $row['trafficUsageMb'] ?? '',
            $row['trafficCost'] ?? '',
            $row['totalCost'] ?? '',
            $row['trafficCostRatio'] ?? '',
            $row['profit'] ?? '',
            $row['roi'] ?? '',
            $row['updatedAt'] ?? '',
        ];
    }

    private function normalizeOrderDirection($value): string
    {
        return is_string($value) && strtolower($value) === 'asc' ? 'asc' : 'desc';
    }

    private function formatDecimal($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 6, '.', '');
    }

    private function ratio(float $a, float $b): ?string
    {
        if ($b == 0.0) {
            return null;
        }

        return $this->formatDecimal($a / $b);
    }

    /**
     * Format a day-over-day ratio from current and previous values.
     */
    private function dayOverDayRatio($current, $previous): ?string
    {
        if ($current === null || $current === '') {
            return null;
        }

        return $this->formatDecimal($this->calculateDayOverDayRatio($current, $previous));
    }

    /**
     * Get the last complete Asia/Shanghai hour for same-time day-over-day comparisons.
     */
    private function currentDayOverDayHourTo(?Carbon $now = null): ?int
    {
        $hour = (int) ($now ?? now('Asia/Shanghai'))->format('G');

        return $hour === 0 ? null : $hour - 1;
    }

    /**
     * Calculate (current - previous) / ABS(previous); return null when previous is unavailable or zero.
     */
    private function calculateDayOverDayRatio(float $current, $previous): ?float
    {
        if ($previous === null || $previous === '') {
            return null;
        }

        $previous = (float) $previous;
        if ($previous == 0.0) {
            return null;
        }

        return ($current - $previous) / abs($previous);
    }

    private function computeRos(float $adRevenue, int $installUsers, int $dauUsers, float $adSpendCost): ?string
    {
        if ($adSpendCost == 0.0 || $dauUsers === 0) {
            return null;
        }

        $ros = ($adRevenue * ($installUsers / $dauUsers)) / $adSpendCost;

        return $this->formatDecimal($ros);
    }

    /**
     * Build a stable key for project/date current revenue lookups.
     */
    private function makeProjectDateKey(string $projectCode, string $reportDate): string
    {
        return trim($projectCode) . "\t" . trim($reportDate);
    }
}
