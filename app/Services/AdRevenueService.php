<?php

namespace App\Services;

use App\Http\Resources\AdRevenueDailyResource;
use App\Http\Resources\CamelizeResource;
use App\Models\AdPlatformApp;
use App\Models\AdRevenueDaily;
use Illuminate\Support\Facades\DB;

class AdRevenueService
{
    private const ALLOWED_ORDER_FIELDS = [
        'report_date', 'impressions', 'clicks', 'estimated_earnings',
        'ecpm', 'ad_requests', 'matched_requests', 'ctr',
    ];

    private const PARAM_COLUMN_MAP = [
        'sourcePlatform'   => 'source_platform',
        'accountId'        => 'account_id',
        'projectId'        => 'project_id',
        'providerAppId'    => 'provider_app_id',
        'providerAdUnitId' => 'provider_ad_unit_id',
        'countryCode'      => 'country_code',
        'devicePlatform'   => 'device_platform',
        'adFormat'         => 'ad_format',
        'reportType'       => 'report_type',
    ];

    private const GROUP_BY_COLUMN_MAP = [
        'reportDate'       => 'report_date',
        'sourcePlatform'   => 'source_platform',
        'accountId'        => 'account_id',
        'providerAppId'    => 'provider_app_id',
        'providerAdUnitId' => 'provider_ad_unit_id',
        'countryCode'      => 'country_code',
        'devicePlatform'   => 'device_platform',
        'adFormat'         => 'ad_format',
        'reportType'       => 'report_type',
        'adSourceCode'     => 'ad_source_code',
    ];

    /**
     * Query paginated ad revenue detail rows.
     */
    public function fetch(array $params): array
    {
        $query = AdRevenueDaily::query();
        $this->applyFilters($params, $query);

        $orderBy = in_array($params['orderBy'] ?? null, self::ALLOWED_ORDER_FIELDS, true)
            ? $params['orderBy']
            : 'report_date';
        $orderDir = $params['orderDir'] ?? 'desc';

        $pageSize = $params['pageSize'] ?? 20;
        $data = $query->orderBy($orderBy, $orderDir)->paginate($pageSize);

        return [
            'data' => AdRevenueDailyResource::collection($data->items()),
            'total' => $data->total(),
            'page' => $data->currentPage(),
            'pageSize' => $data->perPage(),
        ];
    }

    /**
     * Query aggregated ad revenue by requested dimensions.
     */
    public function aggregate(array $params): array
    {
        $groupBy = $params['groupBy'];
        $groupByColumns = $this->mapGroupByColumns($groupBy);
        $pageSize = $params['pageSize'] ?? 20;

        $selectParts = array_merge($groupByColumns, [
            'SUM(ad_requests)        as ad_requests',
            'SUM(matched_requests)   as matched_requests',
            'SUM(impressions)        as impressions',
            'SUM(clicks)             as clicks',
            'SUM(estimated_earnings) as estimated_earnings',
            'ROUND(SUM(estimated_earnings) / NULLIF(SUM(impressions), 0) * 1000, 6) as ecpm',
            'ROUND(SUM(clicks) / NULLIF(SUM(impressions), 0), 6) as ctr',
            'ROUND(SUM(matched_requests) / NULLIF(SUM(ad_requests), 0), 6) as match_rate',
            'ROUND(SUM(impressions) / NULLIF(SUM(matched_requests), 0), 6) as show_rate',
        ]);

        $query = AdRevenueDaily::query()
            ->selectRaw(implode(', ', $selectParts))
            ->groupBy($groupByColumns);

        $this->applyFilters($params, $query);

        $orderBy = in_array($params['orderBy'] ?? null, array_merge(self::ALLOWED_ORDER_FIELDS, $groupBy), true)
            ? $this->mapGroupByColumns([$params['orderBy']])[0]
            : 'estimated_earnings';
        $orderDir = $params['orderDir'] ?? 'desc';
        $query->orderBy($orderBy, $orderDir);

        $countQuery = DB::table(DB::raw("({$query->toSql()}) as sub"))
            ->mergeBindings($query->getQuery());
        $total = $countQuery->count();

        $page = $params['page'] ?? 1;
        $items = $query->offset(($page - 1) * $pageSize)->limit($pageSize)->get();

        return [
            'data' => CamelizeResource::collection($items),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Query revenue trend and optional comparison trend.
     */
    public function trend(array $params): array
    {
        $query = AdRevenueDaily::query()
            ->selectRaw(implode(', ', [
                'report_date',
                'SUM(ad_requests)        as ad_requests',
                'SUM(matched_requests)   as matched_requests',
                'SUM(impressions)        as impressions',
                'SUM(clicks)             as clicks',
                'SUM(estimated_earnings) as estimated_earnings',
                'ROUND(SUM(estimated_earnings) / NULLIF(SUM(impressions), 0) * 1000, 6) as ecpm',
                'ROUND(SUM(clicks) / NULLIF(SUM(impressions), 0), 6) as ctr',
            ]))
            ->groupBy('report_date')
            ->orderBy('report_date');

        $this->applyFilters($params, $query);
        $current = $query->get();

        $compare = null;
        if (!empty($params['compareDateFrom']) && !empty($params['compareDateTo'])) {
            $cmpQuery = AdRevenueDaily::query()
                ->selectRaw(implode(', ', [
                    'report_date',
                    'SUM(ad_requests)        as ad_requests',
                    'SUM(impressions)        as impressions',
                    'SUM(clicks)             as clicks',
                    'SUM(estimated_earnings) as estimated_earnings',
                    'ROUND(SUM(estimated_earnings) / NULLIF(SUM(impressions), 0) * 1000, 6) as ecpm',
                ]))
                ->groupBy('report_date')
                ->orderBy('report_date')
                ->where('report_date', '>=', $params['compareDateFrom'])
                ->where('report_date', '<=', $params['compareDateTo']);

            $this->applyFiltersExceptDate($params, $cmpQuery);
            $compare = $cmpQuery->get();
        }

        return [
            'current' => CamelizeResource::collection($current),
            'compare' => $compare ? CamelizeResource::collection($compare) : null,
        ];
    }

    /**
     * Query ad revenue summary metrics.
     */
    public function summary(array $params)
    {
        $query = AdRevenueDaily::query()
            ->selectRaw(implode(', ', [
                'SUM(ad_requests)        as ad_requests',
                'SUM(matched_requests)   as matched_requests',
                'SUM(impressions)        as impressions',
                'SUM(clicks)             as clicks',
                'SUM(estimated_earnings) as estimated_earnings',
                'ROUND(SUM(estimated_earnings) / NULLIF(SUM(impressions), 0) * 1000, 6) as ecpm',
                'ROUND(SUM(clicks) / NULLIF(SUM(impressions), 0), 6) as ctr',
                'ROUND(SUM(matched_requests) / NULLIF(SUM(ad_requests), 0), 6) as match_rate',
                'COUNT(DISTINCT account_id)      as account_count',
                'COUNT(DISTINCT provider_app_id)  as app_count',
                'COUNT(DISTINCT report_date)      as day_count',
            ]));

        $this->applyFilters($params, $query);
        $data = $query->first();

        return $data ? CamelizeResource::make($data) : null;
    }

    /**
     * Query ad platform app list with account metadata.
     */
    public function fetchApps(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $query = AdPlatformApp::query()->with('account:id,account_name,account_label');

        if (!empty($params['sourcePlatform'])) {
            $query->where('source_platform', $params['sourcePlatform']);
        }
        if (!empty($params['accountId'])) {
            $query->where('account_id', (int) $params['accountId']);
        }
        if (!empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('provider_app_name', 'like', "%{$keyword}%")
                    ->orWhere('app_store_id', 'like', "%{$keyword}%")
                    ->orWhere('provider_app_id', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $rows = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $list = $rows->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'sourcePlatform' => $row->source_platform,
                'accountId' => (int) $row->account_id,
                'accountName' => $row->relationLoaded('account') && $row->account
                    ? $row->account->account_name
                    : null,
                'accountLabel' => $row->relationLoaded('account') && $row->account
                    ? $row->account->account_label
                    : null,
                'providerAppId' => $row->provider_app_id,
                'providerAppName' => $row->provider_app_name,
                'devicePlatform' => $row->device_platform,
                'appStoreId' => $row->app_store_id,
                'appApprovalState' => $row->app_approval_state,
                'createdAt' => $row->created_at,
                'updatedAt' => $row->updated_at,
            ];
        });

        return [
            'data' => $list,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Query top rank data by the requested dimension and metric.
     */
    public function topRank(array $params)
    {
        $rankBy = $params['rankBy'];
        $metric = $params['metric'] ?? 'estimated_earnings';
        $limit = (int) ($params['limit'] ?? 20);

        $dimMap = [
            'app' => ['provider_app_id', 'provider_app_name'],
            'ad_unit' => ['provider_ad_unit_id', 'provider_ad_unit_name'],
            'country' => ['country_code'],
            'account' => ['account_id'],
            'platform' => ['device_platform'],
        ];

        $dims = $dimMap[$rankBy];
        $metricExpr = $metric === 'ecpm'
            ? 'ROUND(SUM(estimated_earnings) / NULLIF(SUM(impressions), 0) * 1000, 6)'
            : "SUM({$metric})";

        $selectParts = array_merge($dims, [
            "{$metricExpr} as {$metric}",
            'SUM(impressions)        as impressions',
            'SUM(clicks)             as clicks',
            'SUM(estimated_earnings) as estimated_earnings',
        ]);

        $query = AdRevenueDaily::query()
            ->selectRaw(implode(', ', $selectParts))
            ->groupBy($dims)
            ->orderByDesc($metric)
            ->limit($limit);

        $this->applyFilters($params, $query);

        return CamelizeResource::collection($query->get());
    }

    /**
     * Query current revenue snapshot and finalized daily revenue differences.
     */
    public function nowDiff(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $query = $this->buildNowDiffQuery();
        $this->applyNowDiffFilters($query, $params);

        $total = (clone $query)->count();
        $orderBy = $this->mapNowDiffOrderBy($params['orderBy'] ?? 'reportDate');
        $orderDir = $params['orderDir'] ?? 'desc';

        $rows = $query->orderBy($orderBy, $orderDir)
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return [
            'data' => $rows->map(fn ($row) => $this->formatNowDiffRow($row))->values(),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Query current revenue snapshot data without comparing finalized daily revenue.
     */
    public function now(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $query = $this->buildNowQuery();
        $this->applyNowFilters($query, $params);

        $total = (clone $query)->count();
        $orderBy = $this->mapNowOrderBy($params['orderBy'] ?? 'reportDate');
        $orderDir = $params['orderDir'] ?? 'desc';

        $rows = $query->orderBy($orderBy, $orderDir)
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return [
            'data' => $rows->map(fn ($row) => $this->formatNowRow($row))->values(),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Aggregate current revenue by project code and report date using the same mapping as now().
     *
     * @return array{byDate: array<string, float>, byProject: array<string, float>}
     */
    public function getNowRevenueByProjectDate(array $projectCodes, string $dateFrom, string $dateTo): array
    {
        $projectCodes = array_values(array_unique(array_filter(array_map(
            static fn ($projectCode) => trim((string) $projectCode),
            $projectCodes
        ), static fn ($projectCode) => $projectCode !== '')));

        if (empty($projectCodes)) {
            return ['byDate' => [], 'byProject' => []];
        }

        $query = DB::query()
            ->fromSub($this->buildNowQuery(), 'mapped_now')
            ->whereIn('mapped_now.project_code', $projectCodes)
            ->where('mapped_now.report_date', '>=', $dateFrom)
            ->where('mapped_now.report_date', '<=', $dateTo)
            ->selectRaw('mapped_now.project_code')
            ->selectRaw('mapped_now.report_date')
            ->selectRaw('SUM(mapped_now.now_estimated_earnings) as now_estimated_earnings')
            ->groupBy('mapped_now.project_code', 'mapped_now.report_date')
            ->get();

        $byDate = [];
        $byProject = [];
        foreach ($query as $row) {
            $projectCode = trim((string) ($row->project_code ?? ''));
            $reportDate = (string) ($row->report_date ?? '');
            if ($projectCode === '' || $reportDate === '') {
                continue;
            }

            $amount = (float) ($row->now_estimated_earnings ?? 0);
            $byDate[$this->makeProjectDateKey($projectCode, $reportDate)] = $amount;
            $byProject[$projectCode] = ($byProject[$projectCode] ?? 0.0) + $amount;
        }

        return ['byDate' => $byDate, 'byProject' => $byProject];
    }

    /**
     * Build current revenue snapshot query with project mapping.
     */
    private function buildNowQuery()
    {
        $nowSub = DB::table('ad_revenue_daily_now')
            ->selectRaw('source_platform, report_type, account_id, report_date, provider_app_id, device_platform')
            ->selectRaw('SUM(estimated_earnings) as now_estimated_earnings')
            ->selectRaw('MAX(updated_at) as now_updated_at')
            ->groupBy('source_platform', 'report_type', 'account_id', 'report_date', 'provider_app_id', 'device_platform');

        $appMapSub = DB::table('project_ad_platform_accounts')
            ->selectRaw('platform_code, ad_platform_account_id, external_app_id, MIN(project_code) as project_code')
            ->where('enabled', 1)
            ->where('bind_type', '!=', 'account')
            ->whereNotNull('external_app_id')
            ->where('external_app_id', '!=', '')
            ->groupBy('platform_code', 'ad_platform_account_id', 'external_app_id');

        $accountMapSub = DB::table('project_ad_platform_accounts')
            ->selectRaw('platform_code, ad_platform_account_id, MIN(project_code) as project_code')
            ->where('enabled', 1)
            ->where('bind_type', '=', 'account')
            ->groupBy('platform_code', 'ad_platform_account_id');

        return DB::query()
            ->fromSub($nowSub, 'now')
            ->leftJoinSub($appMapSub, 'app_map', function ($join) {
                $join->on('app_map.platform_code', '=', 'now.source_platform')
                    ->on('app_map.ad_platform_account_id', '=', 'now.account_id')
                    ->on('app_map.external_app_id', '=', 'now.provider_app_id');
            })
            ->leftJoinSub($accountMapSub, 'account_map', function ($join) {
                $join->on('account_map.platform_code', '=', 'now.source_platform')
                    ->on('account_map.ad_platform_account_id', '=', 'now.account_id');
            })
            ->selectRaw('COALESCE(app_map.project_code, account_map.project_code) as project_code')
            ->selectRaw('now.account_id, now.report_date, now.provider_app_id, now.device_platform, now.source_platform, now.report_type')
            ->selectRaw('now.now_estimated_earnings, now.now_updated_at');
    }

    /**
     * Build now-vs-daily diff query aligned by account, date, app, platform, source, and report type.
     */
    private function buildNowDiffQuery()
    {
        $nowSub = DB::table('ad_revenue_daily_now')
            ->selectRaw('source_platform, report_type, account_id, report_date, provider_app_id, device_platform')
            ->selectRaw('SUM(estimated_earnings) as now_estimated_earnings')
            ->selectRaw('MAX(updated_at) as now_updated_at')
            ->groupBy('source_platform', 'report_type', 'account_id', 'report_date', 'provider_app_id', 'device_platform');

        $dailySub = DB::table('ad_revenue_daily')
            ->selectRaw('source_platform, report_type, account_id, report_date, provider_app_id, device_platform')
            ->selectRaw('SUM(estimated_earnings) as daily_estimated_earnings')
            ->selectRaw('MAX(updated_at) as daily_updated_at')
            ->groupBy('source_platform', 'report_type', 'account_id', 'report_date', 'provider_app_id', 'device_platform');

        $appMapSub = DB::table('project_ad_platform_accounts')
            ->selectRaw('platform_code, ad_platform_account_id, external_app_id, MIN(project_code) as project_code')
            ->where('enabled', 1)
            ->where('bind_type', '!=', 'account')
            ->whereNotNull('external_app_id')
            ->where('external_app_id', '!=', '')
            ->groupBy('platform_code', 'ad_platform_account_id', 'external_app_id');

        $accountMapSub = DB::table('project_ad_platform_accounts')
            ->selectRaw('platform_code, ad_platform_account_id, MIN(project_code) as project_code')
            ->where('enabled', 1)
            ->where('bind_type', '=', 'account')
            ->groupBy('platform_code', 'ad_platform_account_id');

        return DB::query()
            ->fromSub($nowSub, 'now')
            ->leftJoinSub($dailySub, 'daily', function ($join) {
                $join->on('daily.source_platform', '=', 'now.source_platform')
                    ->on('daily.report_type', '=', 'now.report_type')
                    ->on('daily.account_id', '=', 'now.account_id')
                    ->on('daily.report_date', '=', 'now.report_date')
                    ->on('daily.provider_app_id', '=', 'now.provider_app_id')
                    ->on('daily.device_platform', '=', 'now.device_platform');
            })
            ->leftJoinSub($appMapSub, 'app_map', function ($join) {
                $join->on('app_map.platform_code', '=', 'now.source_platform')
                    ->on('app_map.ad_platform_account_id', '=', 'now.account_id')
                    ->on('app_map.external_app_id', '=', 'now.provider_app_id');
            })
            ->leftJoinSub($accountMapSub, 'account_map', function ($join) {
                $join->on('account_map.platform_code', '=', 'now.source_platform')
                    ->on('account_map.ad_platform_account_id', '=', 'now.account_id');
            })
            ->selectRaw('COALESCE(app_map.project_code, account_map.project_code) as project_code')
            ->selectRaw('now.account_id, now.report_date, now.provider_app_id, now.device_platform, now.source_platform, now.report_type')
            ->selectRaw('now.now_estimated_earnings')
            ->selectRaw('COALESCE(daily.daily_estimated_earnings, 0) as daily_estimated_earnings')
            ->selectRaw('(now.now_estimated_earnings - COALESCE(daily.daily_estimated_earnings, 0)) as estimated_earnings_diff')
            ->selectRaw('now.now_updated_at, daily.daily_updated_at');
    }

    private function applyFilters(array $params, $query): void
    {
        $this->applyFiltersExceptDate($params, $query);

        if (!empty($params['dateFrom'])) {
            $query->where('report_date', '>=', $params['dateFrom']);
        }
        if (!empty($params['dateTo'])) {
            $query->where('report_date', '<=', $params['dateTo']);
        }
    }

    private function applyFiltersExceptDate(array $params, $query): void
    {
        foreach (self::PARAM_COLUMN_MAP as $camel => $column) {
            if (!empty($params[$camel])) {
                $query->where($column, '=', $params[$camel]);
            }
        }
    }

    /**
     * Apply filters for the current revenue snapshot query.
     */
    private function applyNowFilters($query, array $params): void
    {
        if (!empty($params['dateFrom'])) {
            $query->where('now.report_date', '>=', $params['dateFrom']);
        }
        if (!empty($params['dateTo'])) {
            $query->where('now.report_date', '<=', $params['dateTo']);
        }
        if (!empty($params['sourcePlatform'])) {
            $query->where('now.source_platform', '=', $params['sourcePlatform']);
        }
        if (!empty($params['reportType'])) {
            $query->where('now.report_type', '=', $params['reportType']);
        }
        if (!empty($params['accountId'])) {
            $query->where('now.account_id', '=', (int) $params['accountId']);
        }
        if (!empty($params['providerAppId'])) {
            $query->where('now.provider_app_id', '=', $params['providerAppId']);
        }
        if (!empty($params['devicePlatform'])) {
            $query->where('now.device_platform', '=', $params['devicePlatform']);
        }
        if (!empty($params['projectCode'])) {
            $query->whereRaw('COALESCE(app_map.project_code, account_map.project_code) = ?', [$params['projectCode']]);
        }
    }

    /**
     * Apply filters for the current-vs-daily revenue diff query.
     */
    private function applyNowDiffFilters($query, array $params): void
    {
        if (!empty($params['dateFrom'])) {
            $query->where('now.report_date', '>=', $params['dateFrom']);
        }
        if (!empty($params['dateTo'])) {
            $query->where('now.report_date', '<=', $params['dateTo']);
        }
        if (!empty($params['sourcePlatform'])) {
            $query->where('now.source_platform', '=', $params['sourcePlatform']);
        }
        if (!empty($params['reportType'])) {
            $query->where('now.report_type', '=', $params['reportType']);
        }
        if (!empty($params['accountId'])) {
            $query->where('now.account_id', '=', (int) $params['accountId']);
        }
        if (!empty($params['providerAppId'])) {
            $query->where('now.provider_app_id', '=', $params['providerAppId']);
        }
        if (!empty($params['devicePlatform'])) {
            $query->where('now.device_platform', '=', $params['devicePlatform']);
        }
        if (!empty($params['projectCode'])) {
            $query->whereRaw('COALESCE(app_map.project_code, account_map.project_code) = ?', [$params['projectCode']]);
        }
    }

    /**
     * Map public now order fields to safe SQL columns or expressions.
     */
    private function mapNowOrderBy(string $orderBy)
    {
        return [
            'reportDate' => 'now.report_date',
            'accountId' => 'now.account_id',
            'providerAppId' => 'now.provider_app_id',
            'devicePlatform' => 'now.device_platform',
            'projectCode' => DB::raw('COALESCE(app_map.project_code, account_map.project_code)'),
            'nowEstimatedEarnings' => 'now_estimated_earnings',
            'nowUpdatedAt' => 'now_updated_at',
        ][$orderBy] ?? 'now.report_date';
    }

    /**
     * Map public now-diff order fields to safe SQL columns or expressions.
     */
    private function mapNowDiffOrderBy(string $orderBy)
    {
        return [
            'reportDate' => 'now.report_date',
            'accountId' => 'now.account_id',
            'providerAppId' => 'now.provider_app_id',
            'devicePlatform' => 'now.device_platform',
            'projectCode' => DB::raw('COALESCE(app_map.project_code, account_map.project_code)'),
            'nowEstimatedEarnings' => 'now_estimated_earnings',
            'dailyEstimatedEarnings' => 'daily_estimated_earnings',
            'estimatedEarningsDiff' => 'estimated_earnings_diff',
            'nowUpdatedAt' => 'now_updated_at',
            'dailyUpdatedAt' => 'daily_updated_at',
        ][$orderBy] ?? 'now.report_date';
    }

    private function mapGroupByColumns(array $groupBy): array
    {
        $mapped = [];
        foreach ($groupBy as $dim) {
            $mapped[] = self::GROUP_BY_COLUMN_MAP[$dim] ?? $dim;
        }

        return $mapped;
    }

    /**
     * Format a current revenue snapshot row using the public camelCase API contract.
     */
    private function formatNowRow(object $row): array
    {
        return [
            'projectCode' => $row->project_code,
            'accountId' => (int) $row->account_id,
            'reportDate' => $row->report_date,
            'providerAppId' => $row->provider_app_id,
            'devicePlatform' => $row->device_platform,
            'sourcePlatform' => $row->source_platform,
            'reportType' => $row->report_type,
            'nowEstimatedEarnings' => number_format((float) $row->now_estimated_earnings, 6, '.', ''),
            'nowUpdatedAt' => $row->now_updated_at,
        ];
    }

    /**
     * Format a now-diff row using the public camelCase API contract.
     */
    private function formatNowDiffRow(object $row): array
    {
        return [
            'projectCode' => $row->project_code,
            'accountId' => (int) $row->account_id,
            'reportDate' => $row->report_date,
            'providerAppId' => $row->provider_app_id,
            'devicePlatform' => $row->device_platform,
            'sourcePlatform' => $row->source_platform,
            'reportType' => $row->report_type,
            'nowEstimatedEarnings' => number_format((float) $row->now_estimated_earnings, 6, '.', ''),
            'dailyEstimatedEarnings' => number_format((float) $row->daily_estimated_earnings, 6, '.', ''),
            'estimatedEarningsDiff' => number_format((float) $row->estimated_earnings_diff, 6, '.', ''),
            'nowUpdatedAt' => $row->now_updated_at,
            'dailyUpdatedAt' => $row->daily_updated_at,
        ];
    }

    /**
     * Build a stable key for project/date current revenue lookups.
     */
    private function makeProjectDateKey(string $projectCode, string $reportDate): string
    {
        return trim($projectCode) . "\t" . trim($reportDate);
    }
}
