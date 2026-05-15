<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CamelizeResource;
use App\Models\TrafficPlatformAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TrafficPlatformUsageController extends Controller
{
    private const TABLE = 'traffic_platform_usage_stat';

    /**
     * 小时流量明细
     * GET /traffic-platform/usages/hourly
     */
    public function hourly(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'platformCode' => 'nullable|string|max:50',
                'accountId'    => 'nullable|integer',
                'externalUid'  => 'nullable|string|max:100',
                'startTime'    => 'nullable|date',
                'endTime'      => 'nullable|date',
                'geo'          => 'nullable|string|max:100',
                'page'         => 'nullable|integer|min:1',
                'pageSize'     => 'nullable|integer|min:1|max:200',
            ]);

            $query = DB::table(self::TABLE);
            $this->applyCommonFilters($query, $request);

            if ($request->filled('startTime')) {
                $query->where('stat_time', '>=', $request->input('startTime'));
            }
            if ($request->filled('endTime')) {
                $query->where('stat_time', '<=', $request->input('endTime'));
            }

            $query->orderByDesc('stat_time');

            $page     = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 50);
            $total    = $query->count();

            $items = $query->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            $items = $this->normalizeDimensionFields($items);

            // 补充 account_name
            $items = $this->attachAccountName($items);

            return $this->ok([
                'page'     => $page,
                'pageSize' => $pageSize,
                'total'    => $total,
                'data'     => CamelizeResource::collection($items),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficUsage hourly error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 日流量汇总
     * GET /traffic-platform/usages/daily
     */
    public function daily(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'platformCode' => 'nullable|string|max:50',
                'accountId'    => 'nullable|integer',
                'externalUid'  => 'nullable|string|max:100',
                'startDate'    => 'nullable|date',
                'endDate'      => 'nullable|date',
                'geo'          => 'nullable|string|max:100',
                'page'         => 'nullable|integer|min:1',
                'pageSize'     => 'nullable|integer|min:1|max:200',
            ]);

            $query = DB::table(self::TABLE)
                ->selectRaw('
                    stat_date,
                    platform_account_id,
                    platform_code,
                    COALESCE(external_uid, "") AS external_uid,
                    external_username,
                    COALESCE(geo, "") AS geo,
                    COALESCE(region, "") AS region,
                    SUM(traffic_bytes) AS traffic_bytes,
                    SUM(traffic_mb) AS traffic_mb,
                    ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb
                ')
                ->groupByRaw('stat_date, platform_account_id, platform_code, COALESCE(external_uid, ""), external_username, COALESCE(geo, ""), COALESCE(region, "")')
                ->orderByDesc('stat_date');

            $this->applyCommonFilters($query, $request);

            if ($request->filled('startDate')) {
                $query->where('stat_date', '>=', $request->input('startDate'));
            }
            if ($request->filled('endDate')) {
                $query->where('stat_date', '<=', $request->input('endDate'));
            }

            $page     = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 50);

            // 用子查询计算 total
            $countQuery = DB::table(DB::raw("({$query->toSql()}) as sub"))
                ->mergeBindings($query);
            $total = $countQuery->count();

            $items = $query->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            $items = $this->normalizeDimensionFields($items);

            $items = $this->attachAccountName($items);

            return $this->ok([
                'page'     => $page,
                'pageSize' => $pageSize,
                'total'    => $total,
                'data'     => CamelizeResource::collection($items),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficUsage daily error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 月流量汇总
     * GET /traffic-platform/usages/monthly
     */
    public function monthly(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'platformCode' => 'nullable|string|max:50',
                'accountId'    => 'nullable|integer',
                'externalUid'  => 'nullable|string|max:100',
                'startMonth'   => 'nullable|string|max:7',
                'endMonth'     => 'nullable|string|max:7',
                'page'         => 'nullable|integer|min:1',
                'pageSize'     => 'nullable|integer|min:1|max:200',
            ]);

            $query = DB::table(self::TABLE)
                ->selectRaw("
                    DATE_FORMAT(stat_date, '%Y-%m') AS stat_month,
                    platform_account_id,
                    platform_code,
                    COALESCE(external_uid, '') AS external_uid,
                    external_username,
                    SUM(traffic_bytes) AS traffic_bytes,
                    SUM(traffic_mb) AS traffic_mb,
                    ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb
                ")
                ->groupByRaw("DATE_FORMAT(stat_date, '%Y-%m'), platform_account_id, platform_code, COALESCE(external_uid, ''), external_username")
                ->orderByDesc('stat_month');

            $this->applyCommonFilters($query, $request);

            if ($request->filled('startMonth')) {
                $query->where('stat_date', '>=', $request->input('startMonth') . '-01');
            }
            if ($request->filled('endMonth')) {
                // 月末最后一天
                $endDate = date('Y-m-t', strtotime($request->input('endMonth') . '-01'));
                $query->where('stat_date', '<=', $endDate);
            }

            $page     = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 50);

            $countQuery = DB::table(DB::raw("({$query->toSql()}) as sub"))
                ->mergeBindings($query);
            $total = $countQuery->count();

            $items = $query->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            $items = $this->normalizeDimensionFields($items);

            $items = $this->attachAccountName($items);

            return $this->ok([
                'page'     => $page,
                'pageSize' => $pageSize,
                'total'    => $total,
                'data'     => CamelizeResource::collection($items),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficUsage monthly error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 流量趋势
     * GET /traffic-platform/usages/trend
     */
    public function trend(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'platformCode' => 'nullable|string|max:50',
                'accountId'    => 'nullable|integer',
                'externalUid'  => 'nullable|string|max:100',
                'startDate'    => 'nullable|date',
                'endDate'      => 'nullable|date',
                'dimension'    => 'nullable|in:hour,day,month',
            ]);

            $dimension = $request->input('dimension', 'day');

            $query = DB::table(self::TABLE);
            $this->applyCommonFilters($query, $request);

            if ($request->filled('startDate')) {
                $query->where('stat_date', '>=', $request->input('startDate'));
            }
            if ($request->filled('endDate')) {
                $query->where('stat_date', '<=', $request->input('endDate'));
            }

            switch ($dimension) {
                case 'hour':
                    $query->selectRaw("DATE_FORMAT(stat_time, '%Y-%m-%d %H:00:00') AS time, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb")
                        ->groupByRaw("DATE_FORMAT(stat_time, '%Y-%m-%d %H:00:00')")
                        ->orderBy('time');
                    break;
                case 'month':
                    $query->selectRaw("DATE_FORMAT(stat_date, '%Y-%m') AS time, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb")
                        ->groupByRaw("DATE_FORMAT(stat_date, '%Y-%m')")
                        ->orderBy('time');
                    break;
                default: // day
                    $query->selectRaw("stat_date AS time, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb")
                        ->groupBy('stat_date')
                        ->orderBy('stat_date');
                    break;
            }

            $data = $query->get();

            return $this->ok([
                'data' => CamelizeResource::collection($data),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficUsage trend error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 账号流量排行
     * GET /traffic-platform/usages/ranking
     */
    public function ranking(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'platformCode' => 'nullable|string|max:50',
                'startDate'    => 'nullable|date',
                'endDate'      => 'nullable|date',
                'rankBy'       => 'nullable|in:account,external_uid,geo',
                'limit'        => 'nullable|integer|min:1|max:100',
            ]);

            $rankBy = $request->input('rankBy', 'account');
            $limit  = (int) $request->input('limit', 20);

            $query = DB::table(self::TABLE);

            if ($request->filled('platformCode')) {
                $query->where('platform_code', $request->input('platformCode'));
            }
            if ($request->filled('startDate')) {
                $query->where('stat_date', '>=', $request->input('startDate'));
            }
            if ($request->filled('endDate')) {
                $query->where('stat_date', '<=', $request->input('endDate'));
            }

            switch ($rankBy) {
                case 'external_uid':
                    $query->selectRaw('platform_account_id, platform_code, COALESCE(external_uid, "") AS external_uid, external_username, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb')
                        ->groupByRaw('platform_account_id, platform_code, COALESCE(external_uid, ""), external_username');
                    break;
                case 'geo':
                    $query->selectRaw('COALESCE(geo, "") AS geo, COALESCE(region, "") AS region, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb')
                        ->groupByRaw('COALESCE(geo, ""), COALESCE(region, "")');
                    break;
                default: // account
                    $query->selectRaw('platform_account_id, platform_code, SUM(traffic_mb) AS traffic_mb, ROUND(SUM(traffic_mb) / 1024, 6) AS traffic_gb')
                        ->groupBy('platform_account_id', 'platform_code');
                    break;
            }

            $data = $query->orderByDesc('traffic_mb')
                ->limit($limit)
                ->get();

            $data = $this->normalizeDimensionFields($data);

            // 补充 account_name
            if (in_array($rankBy, ['account', 'external_uid'])) {
                $data = $this->attachAccountName($data);
            }

            return $this->ok([
                'data' => CamelizeResource::collection($data),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficUsage ranking error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    // ── 私有方法 ──────────────────────────────

    /**
     * 公共筛选条件
     */
    private function applyCommonFilters($query, Request $request): void
    {
        if ($request->filled('platformCode')) {
            $query->where('platform_code', $request->input('platformCode'));
        }
        if ($request->filled('accountId')) {
            $query->where('platform_account_id', $request->input('accountId'));
        }
        if ($request->has('externalUid')) {
            $query->whereRaw('COALESCE(external_uid, "") = ?', [$this->normalizeDimensionValue($request->input('externalUid'))]);
        }
        if ($request->has('geo')) {
            $query->whereRaw('COALESCE(geo, "") = ?', [$this->normalizeDimensionValue($request->input('geo'))]);
        }
    }

    private function normalizeDimensionValue($value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function normalizeDimensionFields($items)
    {
        return collect($items)->map(function ($row) {
            $arr = (array) $row;
            if (array_key_exists('external_uid', $arr)) {
                $arr['external_uid'] = $this->normalizeDimensionValue($arr['external_uid']);
            }
            if (array_key_exists('geo', $arr)) {
                $arr['geo'] = $this->normalizeDimensionValue($arr['geo']);
            }
            if (array_key_exists('region', $arr)) {
                $arr['region'] = $this->normalizeDimensionValue($arr['region']);
            }
            return (object) $arr;
        });
    }

    /**
     * 为结果集补充 account_name
     */
    private function attachAccountName($items)
    {
        $accountIds = collect($items)->pluck('platform_account_id')->unique()->filter()->values();
        if ($accountIds->isEmpty()) {
            return $items;
        }

        $accountMap = TrafficPlatformAccount::whereIn('id', $accountIds)
            ->pluck('account_name', 'id');

        return collect($items)->map(function ($row) use ($accountMap) {
            $row = (array) $row;
            $row['account_name'] = $accountMap[$row['platform_account_id'] ?? 0] ?? '';
            return (object) $row;
        });
    }
}
