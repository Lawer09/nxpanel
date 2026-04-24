<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdRevenueAggregate;
use App\Http\Requests\Admin\AdRevenueFetch;
use App\Http\Requests\Admin\AdRevenueSummary;
use App\Http\Requests\Admin\AdRevenueTopRank;
use App\Http\Requests\Admin\AdRevenueTrend;
use App\Http\Resources\AdRevenueDailyResource;
use App\Http\Resources\CamelizeResource;
use App\Models\AdRevenueDaily;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdRevenueController extends Controller
{
    // 允许的聚合维度白名单
    private const ALLOWED_DIMENSIONS = [
        'report_date', 'source_platform', 'account_id',
        'provider_app_id', 'provider_ad_unit_id',
        'country_code', 'device_platform', 'ad_format',
        'report_type', 'ad_source_code',
    ];

    // 允许的排序字段白名单
    private const ALLOWED_ORDER_FIELDS = [
        'report_date', 'impressions', 'clicks', 'estimated_earnings',
        'ecpm', 'ad_requests', 'matched_requests', 'ctr',
    ];

    /**
     * 明细查询（分页）
     */
    public function fetch(AdRevenueFetch $request): JsonResponse
    {
        $params = $request->validated();

        $query = AdRevenueDaily::query();
        $this->applyFilters($params, $query);

        $orderBy  = in_array($params['orderBy'] ?? null, self::ALLOWED_ORDER_FIELDS)
            ? $params['orderBy'] : 'report_date';
        $orderDir = $params['orderDir'] ?? 'desc';

        $pageSize = $params['pageSize'] ?? 20;
        $data = $query->orderBy($orderBy, $orderDir)->paginate($pageSize);

        return $this->ok([
            'data'     => AdRevenueDailyResource::collection($data->items()),
            'total'    => $data->total(),
            'page'     => $data->currentPage(),
            'pageSize' => $data->perPage(),
        ]);
    }

    /**
     * 多维度聚合查询
     */
    public function aggregate(AdRevenueAggregate $request): JsonResponse
    {
        $params  = $request->validated();
        $groupBy = $params['groupBy'];
        $pageSize = $params['pageSize'] ?? 20;

        $selectParts = array_merge($groupBy, [
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
            ->groupBy($groupBy);

        $this->applyFilters($params, $query);

        $orderBy = in_array($params['orderBy'] ?? null, array_merge(self::ALLOWED_ORDER_FIELDS, $groupBy))
            ? $params['orderBy'] : 'estimated_earnings';
        $orderDir = $params['orderDir'] ?? 'desc';
        $query->orderBy($orderBy, $orderDir);

        $countQuery = DB::table(DB::raw("({$query->toSql()}) as sub"))
            ->mergeBindings($query->getQuery());
        $total = $countQuery->count();

        $page = $params['page'] ?? 1;
        $items = $query->offset(($page - 1) * $pageSize)->limit($pageSize)->get();

        return $this->ok([
            'data'     => CamelizeResource::collection($items),
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * 日期趋势（折线图）
     */
    public function trend(AdRevenueTrend $request): JsonResponse
    {
        $params = $request->validated();

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

        // 对比周期
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

        return $this->ok([
            'current' => CamelizeResource::collection($current),
            'compare' => $compare ? CamelizeResource::collection($compare) : null,
        ]);
    }

    /**
     * 汇总概览（卡片数据）
     */
    public function summary(AdRevenueSummary $request): JsonResponse
    {
        $params = $request->validated();

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

        return $this->ok($data ? CamelizeResource::make($data) : null);
    }

    /**
     * Top 排行榜
     */
    public function topRank(AdRevenueTopRank $request): JsonResponse
    {
        $params = $request->validated();

        $rankBy = $params['rankBy'];
        $metric = $params['metric'] ?? 'estimated_earnings';
        $limit  = (int) ($params['limit'] ?? 20);

        $dimMap = [
            'app'      => ['provider_app_id', 'provider_app_name'],
            'ad_unit'  => ['provider_ad_unit_id', 'provider_ad_unit_name'],
            'country'  => ['country_code'],
            'account'  => ['account_id'],
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

        return $this->ok(CamelizeResource::collection($query->get()));
    }

    // ── camelCase 请求参数 → snake_case 数据库列 映射 ──
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

    // ── 私有：通用筛选 ──────────────────────────
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

}
