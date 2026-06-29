<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProjectReportService
{
    private const QUERY_CACHE_TTL = 60;

    private const PROJECT_METADATA_COLUMNS = [
        'ad_status' => 'adStatus',
        'app_platform' => 'appPlatform',
        'adspower_env' => 'adspowerEnv',
        'developer_gmail' => 'developerGmail',
        'app_name' => 'appName',
        'package_name' => 'packageName',
        'domain_info_status' => 'domainInfoStatus',
        'domain_url' => 'domainUrl',
    ];

    public function __construct(
        private readonly AdRevenueService $adRevenueService,
    ) {}

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

        if ($definition['grouped']) {
            $countQuery = DB::table(DB::raw("({$definition['query']->toSql()}) as t"))
                ->mergeBindings($definition['query'])
                ->selectRaw('COUNT(*) as cnt')
                ->first();
            $total = (int) ($countQuery->cnt ?? 0);
        } else {
            $total = (clone $definition['query'])->count();
        }

        $rows = $definition['query']
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();
        $this->applyDailyNowRevenue($rows, $definition);
        if (($definition['includeLimitState'] ?? false) === true) {
            $this->applyDailyLimitState($rows);
        }

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
            'reportDate' => 'date',
            'hour' => 'hour',
            'projectCode' => 'project_code',
            'country' => 'country',
        ];

        $metricMap = [
            'installUsers' => 'install_users',
            'dauUsers' => 'dau_users',
            'adRevenue' => 'ad_revenue',
            'adSpendCost' => 'ad_spend_cost',
            'ros' => 'ros',
            'id' => 'id',
            'updatedAt' => 'updated_at',
        ];

        $query = DB::table('project_report_hourly')
            ->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo);

        if ($hourFrom !== null) {
            $query->where('hour', '>=', (int) $hourFrom);
        }
        if ($hourTo !== null) {
            $query->where('hour', '<=', (int) $hourTo);
        }

        $projectCodes = is_array($filters['projectCodes'] ?? null) ? $filters['projectCodes'] : [];
        if (!empty($projectCodes)) {
            $query->whereIn('project_code', $projectCodes);
        }

        $countries = is_array($filters['countries'] ?? null) ? $filters['countries'] : [];
        if (!empty($countries)) {
            $query->whereIn('country', array_map(static fn ($country) => strtoupper((string) $country), $countries));
        }
        $this->applyProjectAdStatusFilter($query, 'project_report_hourly.project_code', $filters);
        $this->applyProjectAppPlatformFilter($query, 'project_report_hourly.project_code', $filters);

        if (empty($groupBy)) {
            $sortable = array_merge(array_keys($dimensionMap), array_keys($metricMap));
            $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'reportDate';
            $orderColumn = $dimensionMap[$orderKey] ?? $metricMap[$orderKey] ?? 'date';

            $total = (clone $query)->count();
            $rows = $query;
            if ($orderKey === 'ros') {
                $rows->orderByRaw(
                    'CASE WHEN ad_spend_cost = 0 OR dau_users = 0 THEN NULL ELSE (ad_revenue * (install_users / dau_users)) / ad_spend_cost END ' . $orderDirection
                );
            } else {
                $rows->orderBy($orderColumn, $orderDirection);
            }

            $rows = $rows
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
        } else {
            $groupDimensions = array_values(array_unique(array_filter($groupBy, static fn ($item) => is_string($item) && isset($dimensionMap[$item]))));
            if (empty($groupDimensions)) {
                $groupDimensions = ['reportDate', 'hour'];
            }

            $groupColumns = array_map(static fn ($key) => $dimensionMap[$key], $groupDimensions);
            $groupQuery = clone $query;
            foreach ($groupColumns as $groupColumn) {
                $groupQuery->selectRaw($groupColumn);
                $groupQuery->groupBy($groupColumn);
            }

            $groupQuery->selectRaw('SUM(install_users) as install_users')
                ->selectRaw('SUM(dau_users) as dau_users')
                ->selectRaw('SUM(ad_revenue) as ad_revenue')
                ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
                ->selectRaw('CASE WHEN SUM(ad_spend_cost)=0 OR SUM(dau_users)=0 THEN NULL ELSE ROUND((SUM(ad_revenue) * (SUM(install_users) / SUM(dau_users))) / SUM(ad_spend_cost),6) END as ros')
                ->selectRaw('MAX(updated_at) as updated_at');

            $sortable = array_values(array_unique(array_merge($groupDimensions, [
                'installUsers', 'dauUsers', 'adRevenue', 'adSpendCost', 'ros', 'updatedAt',
            ])));

            $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'adRevenue';
            $orderColumn = $dimensionMap[$orderKey] ?? $metricMap[$orderKey] ?? 'ad_revenue';

            $countQuery = DB::table(DB::raw("({$groupQuery->toSql()}) as t"))
                ->mergeBindings($groupQuery)
                ->selectRaw('COUNT(*) as cnt')
                ->first();
            $total = (int) ($countQuery->cnt ?? 0);

            $rows = $groupQuery
                ->orderBy($orderColumn, $orderDirection)
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
        }

        $data = $rows->map(function ($row) {
            return [
                'id' => isset($row->id) ? (int) $row->id : null,
                'reportDate' => isset($row->date) ? (string) $row->date : null,
                'hour' => isset($row->hour) ? (int) $row->hour : null,
                'projectCode' => $row->project_code ?? null,
                'country' => $row->country ?? null,
                'installUsers' => (int) ($row->install_users ?? 0),
                'dauUsers' => (int) ($row->dau_users ?? 0),
                'adRevenue' => $this->formatDecimal($row->ad_revenue ?? null),
                'adSpendCost' => $this->formatDecimal($row->ad_spend_cost ?? null),
                'ros' => $this->computeRos(
                    (float) ($row->ad_revenue ?? 0),
                    (int) ($row->install_users ?? 0),
                    (int) ($row->dau_users ?? 0),
                    (float) ($row->ad_spend_cost ?? 0)
                ),
                'updatedAt' => $row->updated_at ?? null,
            ];
        });

        return [
            'data' => $data,
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

        return sprintf('project_report:%s_query:v2:%s', $scope, md5((string) $payload));
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
        }

        return $params;
    }

    /**
     * Normalize filters so semantically identical requests share the same cache key.
     */
    private function normalizeCacheFilters(array $filters): array
    {
        foreach (['projectCodes', 'countries', 'adStatuses', 'appPlatforms'] as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = $this->normalizeStringList($filters[$key]);
                if ($key === 'countries') {
                    $filters[$key] = array_map(static fn ($country) => strtoupper($country), $filters[$key]);
                }
                sort($filters[$key], SORT_STRING);
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

        $projectCodes = is_array($filters['projectCodes'] ?? null) ? $filters['projectCodes'] : [];
        if (!empty($projectCodes)) {
            $baseQuery->whereIn('project_daily_aggregates.project_code', $projectCodes);
        }

        $countries = is_array($filters['countries'] ?? null) ? $filters['countries'] : [];
        if (!empty($countries)) {
            $baseQuery->whereIn('project_daily_aggregates.country', array_map(static fn ($country) => strtoupper((string) $country), $countries));
        }
        $this->applyProjectAdStatusFilter($baseQuery, 'project_daily_aggregates.project_code', $filters);
        $this->applyProjectAppPlatformFilter($baseQuery, 'project_daily_aggregates.project_code', $filters);

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
            'includeLimitState' => in_array('projectCode', $groupDimensions, true),
        ];
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
        $this->joinDailySpendMetrics(
            $summaryQuery,
            $this->buildDailySpendMetricSubquery($definition['dateFrom'], $definition['dateTo'], $definition['filters'] ?? [])
        );

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
            ->selectRaw('COALESCE(SUM(spend_metrics.ad_spend_cost), 0) as ad_spend_cost')
            ->selectRaw('SUM(spend_metrics.ad_spend_clicks) as ad_spend_clicks')
            ->selectRaw('SUM(spend_metrics.ad_spend_impressions) as ad_spend_impressions')
            ->selectRaw('SUM(project_daily_aggregates.traffic_usage_mb) as traffic_usage_mb')
            ->selectRaw('SUM(project_daily_aggregates.traffic_cost) as traffic_cost')
            ->selectRaw('(SUM(project_daily_aggregates.ad_revenue) - COALESCE(SUM(spend_metrics.ad_spend_cost), 0) - SUM(project_daily_aggregates.traffic_cost)) as profit')
            ->selectRaw('MAX(project_daily_aggregates.updated_at) as updated_at')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_impressions)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_revenue)/SUM(project_daily_aggregates.ad_impressions)*1000,6) END as ad_ecpm')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_impressions)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_clicks)/SUM(project_daily_aggregates.ad_impressions)*100,6) END as ad_ctr')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_requests)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_matched_requests)/SUM(project_daily_aggregates.ad_requests)*100,6) END as ad_match_rate')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.ad_matched_requests)=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_impressions)/SUM(project_daily_aggregates.ad_matched_requests)*100,6) END as ad_show_rate')
            ->selectRaw('CASE WHEN SUM(project_daily_aggregates.new_users)=0 THEN NULL ELSE ROUND(COALESCE(SUM(spend_metrics.ad_spend_cost), 0)/SUM(project_daily_aggregates.new_users),6) END as ad_spend_cpi')
            ->selectRaw('CASE WHEN COALESCE(SUM(spend_metrics.ad_spend_clicks), 0)=0 THEN NULL ELSE ROUND(COALESCE(SUM(spend_metrics.ad_spend_cost), 0)/SUM(spend_metrics.ad_spend_clicks),6) END as ad_spend_cpc')
            ->selectRaw('CASE WHEN COALESCE(SUM(spend_metrics.ad_spend_impressions), 0)=0 THEN NULL ELSE ROUND(COALESCE(SUM(spend_metrics.ad_spend_cost), 0)*1000/SUM(spend_metrics.ad_spend_impressions),6) END as ad_spend_cpm')
            ->selectRaw('CASE WHEN (COALESCE(SUM(spend_metrics.ad_spend_cost), 0)+COALESCE(SUM(project_daily_aggregates.traffic_cost), 0))=0 THEN NULL ELSE ROUND(COALESCE(SUM(project_daily_aggregates.traffic_cost), 0)/(COALESCE(SUM(spend_metrics.ad_spend_cost), 0)+COALESCE(SUM(project_daily_aggregates.traffic_cost), 0)),6) END as traffic_cost_ratio')
            ->selectRaw('CASE WHEN (COALESCE(SUM(spend_metrics.ad_spend_cost), 0)+SUM(project_daily_aggregates.traffic_cost))=0 THEN NULL ELSE ROUND(SUM(project_daily_aggregates.ad_revenue)/(COALESCE(SUM(spend_metrics.ad_spend_cost), 0)+SUM(project_daily_aggregates.traffic_cost)),6) END as roi')
            ->first();

        $adRevenueNow = $this->buildDailySummaryNowRevenue($definition);
        $adRevenueDiff = $adRevenueNow === null
            ? null
            : $adRevenueNow - (float) ($row->ad_revenue ?? 0);

        return [
            'newUsers' => (int) ($row->new_users ?? 0),
            'reportNewUsers' => (int) ($row->report_new_users ?? 0),
            'fbNewUsers' => (int) ($row->fb_new_users ?? 0),
            'dauUsers' => (int) ($row->dau_users ?? 0),
            'fbDauUsers' => (int) ($row->fb_dau_users ?? 0),
            'adRevenue' => $this->formatDecimal($row->ad_revenue ?? null),
            'adRevenueNow' => $this->formatDecimal($adRevenueNow),
            'adRevenueDiff' => $this->formatDecimal($adRevenueDiff),
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
        $cacheKey = 'project_report:is_limited_metrics:v4:' . $previousHourString;

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
                ->where('date', '=', $previousHourDate)
                ->where('hour', '=', $previousHourValue)
                ->whereNotNull('project_code')
                ->where('project_code', '!=', '')
                ->selectRaw('project_code')
                ->selectRaw('SUM(install_users) as hourly_new_users')
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

        $projectCodes = is_array($filters['projectCodes'] ?? null) ? $filters['projectCodes'] : [];
        if (!empty($projectCodes)) {
            $query->whereIn('project_code', $projectCodes);
        }

        $countries = is_array($filters['countries'] ?? null) ? $filters['countries'] : [];
        if (!empty($countries)) {
            $normalizedCountries = array_map(static fn ($country) => strtoupper((string) $country), $countries);
            $query->whereIn(DB::raw($countryExpression), $normalizedCountries);
        }

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

        return $data;
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
