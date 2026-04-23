<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdRevenueDaily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function fetch(Request $request): JsonResponse
    {
        $request->validate([
            'source_platform'    => 'nullable|string|max:32',
            'account_id'         => 'nullable|integer',
            'project_id'         => 'nullable|integer',
            'provider_app_id'    => 'nullable|string|max:128',
            'provider_ad_unit_id'=> 'nullable|string|max:128',
            'country_code'       => 'nullable|string|max:16',
            'device_platform'    => 'nullable|string|max:32',
            'ad_format'          => 'nullable|string|max:64',
            'report_type'        => 'nullable|string|max:32',
            'date_from'          => 'nullable|date',
            'date_to'            => 'nullable|date',
            'page'               => 'nullable|integer|min:1',
            'size'               => 'nullable|integer|min:1|max:200',
            'order_by'           => 'nullable|string',
            'order_dir'          => 'nullable|in:asc,desc',
        ]);

        $page = (int) $request->input('page', 1);
        $size = (int) $request->input('size', 20);

        $query = AdRevenueDaily::query();
        $this->applyFilters($request, $query);

        $orderBy  = in_array($request->input('order_by'), self::ALLOWED_ORDER_FIELDS)
            ? $request->input('order_by') : 'report_date';
        $orderDir = $request->input('order_dir', 'desc');

        $total = $query->count();
        $items = $query->orderBy($orderBy, $orderDir)
            ->offset(($page - 1) * $size)
            ->limit($size)
            ->get();

        return $this->ok(compact('page', 'size', 'total', 'items'));
    }

    /**
     * 多维度聚合查询
     */
    public function aggregate(Request $request): JsonResponse
    {
        $request->validate([
            'group_by'           => 'required|array|min:1',
            'group_by.*'         => 'string|in:' . implode(',', self::ALLOWED_DIMENSIONS),
            'source_platform'    => 'nullable|string|max:32',
            'account_id'         => 'nullable|integer',
            'project_id'         => 'nullable|integer',
            'provider_app_id'    => 'nullable|string|max:128',
            'country_code'       => 'nullable|string|max:16',
            'device_platform'    => 'nullable|string|max:32',
            'ad_format'          => 'nullable|string|max:64',
            'date_from'          => 'nullable|date',
            'date_to'            => 'nullable|date',
            'page'               => 'nullable|integer|min:1',
            'size'               => 'nullable|integer|min:1|max:200',
            'order_by'           => 'nullable|string',
            'order_dir'          => 'nullable|in:asc,desc',
        ]);

        $groupBy = $request->input('group_by');
        $page    = (int) $request->input('page', 1);
        $size    = (int) $request->input('size', 20);

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

        $this->applyFilters($request, $query);

        $orderBy = in_array($request->input('order_by'), array_merge(self::ALLOWED_ORDER_FIELDS, $groupBy))
            ? $request->input('order_by') : 'estimated_earnings';
        $orderDir = $request->input('order_dir', 'desc');
        $query->orderBy($orderBy, $orderDir);

        $countQuery = DB::table(DB::raw("({$query->toSql()}) as sub"))
            ->mergeBindings($query->getQuery());
        $total = $countQuery->count();

        $items = $query->offset(($page - 1) * $size)->limit($size)->get();

        return $this->ok(compact('page', 'size', 'total', 'items'));
    }

    /**
     * 日期趋势（折线图）
     */
    public function trend(Request $request): JsonResponse
    {
        $request->validate([
            'source_platform'    => 'nullable|string|max:32',
            'account_id'         => 'nullable|integer',
            'project_id'         => 'nullable|integer',
            'provider_app_id'    => 'nullable|string|max:128',
            'country_code'       => 'nullable|string|max:16',
            'device_platform'    => 'nullable|string|max:32',
            'ad_format'          => 'nullable|string|max:64',
            'date_from'          => 'nullable|date',
            'date_to'            => 'nullable|date',
            'compare_date_from'  => 'nullable|date',
            'compare_date_to'    => 'nullable|date',
        ]);

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

        $this->applyFilters($request, $query);
        $current = $query->get();

        // 对比周期
        $compare = null;
        if ($request->filled('compare_date_from') && $request->filled('compare_date_to')) {
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
                ->where('report_date', '>=', $request->input('compare_date_from'))
                ->where('report_date', '<=', $request->input('compare_date_to'));

            $this->applyFiltersExceptDate($request, $cmpQuery);
            $compare = $cmpQuery->get();
        }

        return $this->ok([
            'current' => $current,
            'compare' => $compare,
        ]);
    }

    /**
     * 汇总概览（卡片数据）
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'source_platform' => 'nullable|string|max:32',
            'account_id'      => 'nullable|integer',
            'project_id'      => 'nullable|integer',
            'date_from'       => 'nullable|date',
            'date_to'         => 'nullable|date',
        ]);

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

        $this->applyFilters($request, $query);
        $data = $query->first();

        return $this->ok($data);
    }

    /**
     * Top 排行榜
     */
    public function topRank(Request $request): JsonResponse
    {
        $request->validate([
            'rank_by'         => 'required|in:app,ad_unit,country,account,platform',
            'metric'          => 'nullable|in:estimated_earnings,impressions,clicks,ecpm',
            'date_from'       => 'nullable|date',
            'date_to'         => 'nullable|date',
            'source_platform' => 'nullable|string|max:32',
            'account_id'      => 'nullable|integer',
            'project_id'      => 'nullable|integer',
            'limit'           => 'nullable|integer|min:1|max:100',
        ]);

        $rankBy = $request->input('rank_by');
        $metric = $request->input('metric', 'estimated_earnings');
        $limit  = (int) $request->input('limit', 20);

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

        $this->applyFilters($request, $query);

        return $this->ok($query->get());
    }

    // ── 私有：通用筛选 ──────────────────────────
    private function applyFilters(Request $request, $query): void
    {
        $this->applyFiltersExceptDate($request, $query);

        if ($request->filled('date_from')) {
            $query->where('report_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('report_date', '<=', $request->input('date_to'));
        }
    }

    private function applyFiltersExceptDate(Request $request, $query): void
    {
        $filters = [
            'source_platform'     => '=',
            'account_id'          => '=',
            'project_id'          => '=',
            'provider_app_id'     => '=',
            'provider_ad_unit_id' => '=',
            'country_code'        => '=',
            'device_platform'     => '=',
            'ad_format'           => '=',
            'report_type'         => '=',
        ];

        foreach ($filters as $field => $op) {
            if ($request->filled($field)) {
                $query->where($field, $op, $request->input($field));
            }
        }
    }
}
