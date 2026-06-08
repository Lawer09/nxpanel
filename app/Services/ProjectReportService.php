<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ProjectReportService
{
    /**
     * Query project daily aggregate report.
     */
    public function queryDaily(array $validated): array
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

        $data = $rows->map(fn ($row) => $this->formatDailyRow($row));

        return [
            'data' => $data,
            'summary' => $this->buildDailySummary(clone $definition['baseQuery']),
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
            'reportDate' => 'report_date',
            'projectCode' => 'project_code',
            'country' => 'country',
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
            'profit' => 'profit',
            'roi' => 'roi',
            'id' => 'id',
            'updatedAt' => 'updated_at',
        ];

        $baseQuery = DB::table('project_daily_aggregates')
            ->where('report_date', '>=', $dateFrom)
            ->where('report_date', '<=', $dateTo);

        $projectCodes = is_array($filters['projectCodes'] ?? null) ? $filters['projectCodes'] : [];
        if (!empty($projectCodes)) {
            $baseQuery->whereIn('project_code', $projectCodes);
        }

        $countries = is_array($filters['countries'] ?? null) ? $filters['countries'] : [];
        if (!empty($countries)) {
            $baseQuery->whereIn('country', array_map(static fn ($country) => strtoupper((string) $country), $countries));
        }

        if (empty($requestedGroupBy)) {
            $sortable = array_merge(array_keys($dimensionMap), array_keys($metricMap));
            $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'reportDate';
            $query = clone $baseQuery;

            $this->applyDailyOrder($query, $orderKey, $orderDirection, $dimensionMap, $metricMap, 'report_date', false);

            return [
                'baseQuery' => $baseQuery,
                'query' => $query,
                'grouped' => false,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'requestedGroupBy' => $requestedGroupBy,
            ];
        }

        $groupDimensions = array_values(array_unique(array_filter($requestedGroupBy, static fn ($item) => is_string($item) && isset($dimensionMap[$item]))));
        if (empty($groupDimensions)) {
            $groupDimensions = ['reportDate'];
        }

        $groupColumns = array_map(static fn ($key) => $dimensionMap[$key], $groupDimensions);
        $query = clone $baseQuery;

        foreach ($groupColumns as $groupColumn) {
            $query->selectRaw($groupColumn);
            $query->groupBy($groupColumn);
        }

        $query->selectRaw('SUM(new_users) as new_users')
            ->selectRaw('SUM(report_new_users) as report_new_users')
            ->selectRaw('SUM(fb_new_users) as fb_new_users')
            ->selectRaw('SUM(dau_users) as dau_users')
            ->selectRaw('SUM(fb_dau_users) as fb_dau_users')
            ->selectRaw('SUM(ad_revenue) as ad_revenue')
            ->selectRaw('SUM(ad_requests) as ad_requests')
            ->selectRaw('SUM(ad_matched_requests) as ad_matched_requests')
            ->selectRaw('SUM(ad_impressions) as ad_impressions')
            ->selectRaw('SUM(ad_clicks) as ad_clicks')
            ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
            ->selectRaw('SUM(traffic_usage_mb) as traffic_usage_mb')
            ->selectRaw('SUM(traffic_cost) as traffic_cost')
            ->selectRaw('(SUM(ad_spend_cost) + SUM(traffic_cost)) as total_cost')
            ->selectRaw('SUM(profit) as profit')
            ->selectRaw('MAX(updated_at) as updated_at')
            ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_revenue)/SUM(ad_impressions)*1000,6) END as ad_ecpm')
            ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_clicks)/SUM(ad_impressions)*100,6) END as ad_ctr')
            ->selectRaw('CASE WHEN SUM(ad_requests)=0 THEN NULL ELSE ROUND(SUM(ad_matched_requests)/SUM(ad_requests)*100,6) END as ad_match_rate')
            ->selectRaw('CASE WHEN SUM(ad_matched_requests)=0 THEN NULL ELSE ROUND(SUM(ad_impressions)/SUM(ad_matched_requests)*100,6) END as ad_show_rate')
            ->selectRaw('CASE WHEN SUM(new_users)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)/SUM(new_users),6) END as ad_spend_cpi')
            ->selectRaw('CASE WHEN SUM(ad_clicks)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)/SUM(ad_clicks),6) END as ad_spend_cpc')
            ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)*1000/SUM(ad_impressions),6) END as ad_spend_cpm')
            ->selectRaw('CASE WHEN (SUM(ad_spend_cost)+SUM(traffic_cost))=0 THEN NULL ELSE ROUND(SUM(ad_revenue)/(SUM(ad_spend_cost)+SUM(traffic_cost)),6) END as roi');

        $sortable = array_values(array_unique(array_merge($groupDimensions, [
            'newUsers', 'reportNewUsers', 'fbNewUsers', 'dauUsers', 'fbDauUsers', 'adRevenue', 'adRequests', 'adMatchedRequests',
            'adImpressions', 'adClicks', 'adEcpm', 'adCtr', 'adMatchRate', 'adShowRate',
            'adSpendCost', 'adSpendCpi', 'adSpendCpc', 'adSpendCpm', 'trafficUsageMb',
            'trafficCost', 'totalCost', 'profit', 'roi', 'updatedAt',
        ])));

        $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'adRevenue';
        $this->applyDailyOrder($query, $orderKey, $orderDirection, $dimensionMap, $metricMap, 'ad_revenue', true);

        return [
            'baseQuery' => $baseQuery,
            'query' => $query,
            'grouped' => true,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'requestedGroupBy' => $requestedGroupBy,
        ];
    }

    /**
     * Apply daily report ordering, including computed total cost.
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

    private function buildDailySummary(Builder $query): array
    {
        $row = $query
            ->selectRaw('SUM(new_users) as new_users')
            ->selectRaw('SUM(report_new_users) as report_new_users')
            ->selectRaw('SUM(fb_new_users) as fb_new_users')
            ->selectRaw('SUM(dau_users) as dau_users')
            ->selectRaw('SUM(fb_dau_users) as fb_dau_users')
            ->selectRaw('SUM(ad_revenue) as ad_revenue')
            ->selectRaw('SUM(ad_requests) as ad_requests')
            ->selectRaw('SUM(ad_matched_requests) as ad_matched_requests')
            ->selectRaw('SUM(ad_impressions) as ad_impressions')
            ->selectRaw('SUM(ad_clicks) as ad_clicks')
            ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
            ->selectRaw('SUM(traffic_usage_mb) as traffic_usage_mb')
            ->selectRaw('SUM(traffic_cost) as traffic_cost')
            ->selectRaw('SUM(profit) as profit')
            ->selectRaw('MAX(updated_at) as updated_at')
            ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_revenue)/SUM(ad_impressions)*1000,6) END as ad_ecpm')
            ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_clicks)/SUM(ad_impressions)*100,6) END as ad_ctr')
            ->selectRaw('CASE WHEN SUM(ad_requests)=0 THEN NULL ELSE ROUND(SUM(ad_matched_requests)/SUM(ad_requests)*100,6) END as ad_match_rate')
            ->selectRaw('CASE WHEN SUM(ad_matched_requests)=0 THEN NULL ELSE ROUND(SUM(ad_impressions)/SUM(ad_matched_requests)*100,6) END as ad_show_rate')
            ->selectRaw('CASE WHEN SUM(new_users)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)/SUM(new_users),6) END as ad_spend_cpi')
            ->selectRaw('CASE WHEN SUM(ad_clicks)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)/SUM(ad_clicks),6) END as ad_spend_cpc')
            ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)*1000/SUM(ad_impressions),6) END as ad_spend_cpm')
            ->selectRaw('CASE WHEN (SUM(ad_spend_cost)+SUM(traffic_cost))=0 THEN NULL ELSE ROUND(SUM(ad_revenue)/(SUM(ad_spend_cost)+SUM(traffic_cost)),6) END as roi')
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
            'profit' => $this->formatDecimal($row->profit ?? null),
            'roi' => $this->formatDecimal($row->roi ?? null),
            'updatedAt' => $row->updated_at ?? null,
        ];
    }

    private function formatDailyRow(object $row): array
    {
        return [
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
            'profit' => $this->formatDecimal($row->profit ?? null),
            'roi' => $this->formatDecimal($row->roi ?? null),
            'updatedAt' => $row->updated_at ?? null,
        ];
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
}
