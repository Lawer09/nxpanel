<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 应用流量报表
 *
 * 基于用户 register_metadata 中的 app_id / app_version 维度，
 * 统计流量消耗（上行 u、下行 d、总量 total）。
 */
class AppTrafficController extends Controller
{
    /**
     * 聚合流量统计（按 app_id 或 app_id + app_version）
     *
     * GET stat/appTraffic/aggregate
     *
     * Query params:
    *   group_by    array    optional  聚合维度：app_id / app_version（可组合）
     *   app_id      string   optional  筛选指定 app_id
     *   app_version string   optional  筛选指定 app_version
     *   min_total      integer  optional  流量下限（总量 u+d，字节）
     *   min_user_total integer  optional  用户流量下限（用户在时间范围内的总流量 u+d，字节）
     *   start_time  integer  optional  起始时间戳（10 位），默认 30 天前
     *   end_time    integer  optional  结束时间戳（10 位），默认当前时间
     *   page        integer  optional  页码，默认 1
     *   pageSize    integer  optional  每页条数，默认 15
     */
    public function aggregate(Request $request): JsonResponse
    {
        $request->validate([
            'group_by'   => 'nullable|array',
            'group_by.*' => 'in:app_id,app_version',
            'app_id'     => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:50',
            'min_total'      => 'nullable|integer|min:0',
            'min_user_total' => 'nullable|integer|min:0',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time'   => 'nullable|integer|min:1000000000|max:9999999999',
            'page'       => 'nullable|integer|min:1',
            'pageSize'   => 'nullable|integer|min:1|max:100',
        ]);

        $startTime = (int) $request->input('start_time', strtotime('-30 days'));
        $endTime   = (int) $request->input('end_time', time());
        $page      = (int) $request->input('page', 1);
        $pageSize  = (int) $request->input('pageSize', 15);
        $groupBy   = $request->input('group_by', ['app_id']);
        $minTotal     = $request->input('min_total');
        $minUserTotal = $request->input('min_user_total');

        if (!is_array($groupBy)) {
            return $this->error([422, 'group_by 必须为数组']);
        }
        if (!in_array('app_id', $groupBy, true)) {
            return $this->error([422, 'group_by 必须包含 app_id']);
        }

        $select = [
            "JSON_UNQUOTE(JSON_EXTRACT(v2_user.register_metadata, '$.app_id')) as app_id",
        ];
        $groupByRaw = [
            "JSON_UNQUOTE(JSON_EXTRACT(v2_user.register_metadata, '$.app_id'))",
        ];

        if (in_array('app_version', $groupBy, true)) {
            $select[] = "JSON_UNQUOTE(JSON_EXTRACT(v2_user.register_metadata, '$.app_version')) as app_version";
            $groupByRaw[] = "JSON_UNQUOTE(JSON_EXTRACT(v2_user.register_metadata, '$.app_version'))";
        }

        $select[] = 'SUM(v2_stat_user.u) as u';
        $select[] = 'SUM(v2_stat_user.d) as d';
        $select[] = 'SUM(v2_stat_user.u + v2_stat_user.d) as total';
        $select[] = 'COUNT(DISTINCT v2_stat_user.user_id) as user_count';

        $query = StatUser::query()
            ->join('v2_user', 'v2_stat_user.user_id', '=', 'v2_user.id')
            ->whereNotNull('v2_user.register_metadata')
            ->where('v2_stat_user.record_at', '>=', $startTime)
            ->where('v2_stat_user.record_at', '<=', $endTime)
            ->selectRaw(implode(",\n", $select))
            ->groupByRaw(implode(",\n", $groupByRaw));

        if ($minUserTotal !== null) {
            $userTrafficSub = StatUser::query()
                ->selectRaw('user_id, SUM(u + d) as total')
                ->where('record_at', '>=', $startTime)
                ->where('record_at', '<=', $endTime)
                ->groupBy('user_id');

            $query->joinSub($userTrafficSub, 'user_traffic', function ($join) {
                $join->on('user_traffic.user_id', '=', 'v2_stat_user.user_id');
            })->where('user_traffic.total', '>=', (int) $minUserTotal);
        }

        if ($request->filled('app_id')) {
            $query->havingRaw("app_id = ?", [$request->input('app_id')]);
        }
        if ($request->filled('app_version')) {
            $query->havingRaw("app_version = ?", [$request->input('app_version')]);
        }

        // 排除 app_id 为 null 的记录
        $query->havingRaw("app_id IS NOT NULL");

        if ($minTotal !== null) {
            $query->havingRaw("total >= ?", [(int) $minTotal]);
        }

        $total = DB::table(DB::raw("({$query->toSql()}) as sub"))
            ->mergeBindings($query->getQuery())
            ->count();

        $items = $query->orderByDesc('total')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->ok([
            'data'     => $items,
            'total'    => $total,
            'page'     => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * 按日期趋势查看指定 app_id / app_version 的流量消耗
     *
     * GET stat/appTraffic/trend
     *
     * Query params:
     *   app_id       string   optional  筛选指定 app_id
     *   app_version  string   optional  筛选指定 app_version
     *   start_time   integer  optional  起始时间戳（10 位），默认 30 天前
     *   end_time     integer  optional  结束时间戳（10 位），默认当前时间
     */
    public function trend(Request $request): JsonResponse
    {
        $request->validate([
            'app_id'      => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:50',
            'start_time'  => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time'    => 'nullable|integer|min:1000000000|max:9999999999',
        ]);

        $startTime = (int) $request->input('start_time', strtotime('-30 days'));
        $endTime   = (int) $request->input('end_time', time());

        $query = StatUser::query()
            ->join('v2_user', 'v2_stat_user.user_id', '=', 'v2_user.id')
            ->whereNotNull('v2_user.register_metadata')
            ->where('v2_stat_user.record_at', '>=', $startTime)
            ->where('v2_stat_user.record_at', '<=', $endTime)
            ->selectRaw("
                v2_stat_user.record_at as record_at,
                SUM(v2_stat_user.u) as u,
                SUM(v2_stat_user.d) as d,
                SUM(v2_stat_user.u + v2_stat_user.d) as total,
                COUNT(DISTINCT v2_stat_user.user_id) as user_count
            ")
            ->groupBy('v2_stat_user.record_at');

        if ($request->filled('app_id')) {
            $query->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(v2_user.register_metadata, '$.app_id')) = ?",
                [$request->input('app_id')]
            );
        }
        if ($request->filled('app_version')) {
            $query->whereRaw(
                "JSON_UNQUOTE(JSON_EXTRACT(v2_user.register_metadata, '$.app_version')) = ?",
                [$request->input('app_version')]
            );
        }

        $items = $query->orderBy('record_at', 'ASC')->get()->map(function ($row) {
            return [
                'record_at'  => $row->record_at,
                'date'       => date('Y-m-d', $row->record_at),
                'u'          => (int) $row->u,
                'd'          => (int) $row->d,
                'total'      => (int) $row->total,
                'user_count' => (int) $row->user_count,
            ];
        });

        return $this->ok([
            'list' => $items,
        ]);
    }

    /**
     * 实时流量汇总（基于用户表 u/d 字段）
     *
     * GET stat/appTraffic/summary
     *
     * Query params:
     *   app_id       string   optional  筛选指定 app_id
     *   app_version  string   optional  筛选指定 app_version
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'app_id'      => 'nullable|string|max:255',
            'app_version' => 'nullable|string|max:50',
        ]);

        $query = User::query()
            ->whereNotNull('register_metadata')
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT(register_metadata, '$.app_id'))      as app_id,
                JSON_UNQUOTE(JSON_EXTRACT(register_metadata, '$.app_version'))  as app_version,
                SUM(u) as u,
                SUM(d) as d,
                SUM(u + d) as total,
                SUM(transfer_enable) as transfer_enable,
                COUNT(*) as user_count
            ")
            ->groupByRaw("
                JSON_UNQUOTE(JSON_EXTRACT(register_metadata, '$.app_id')),
                JSON_UNQUOTE(JSON_EXTRACT(register_metadata, '$.app_version'))
            ")
            ->havingRaw("app_id IS NOT NULL");

        if ($request->filled('app_id')) {
            $query->havingRaw("app_id = ?", [$request->input('app_id')]);
        }
        if ($request->filled('app_version')) {
            $query->havingRaw("app_version = ?", [$request->input('app_version')]);
        }

        $items = $query->orderByDesc('total')->get()->map(function ($row) {
            return [
                'app_id'          => $row->app_id,
                'app_version'     => $row->app_version,
                'u'               => (int) $row->u,
                'd'               => (int) $row->d,
                'total'           => (int) $row->total,
                'transfer_enable' => (int) $row->transfer_enable,
                'user_count'      => (int) $row->user_count,
            ];
        });

        // 汇总
        $totalU              = $items->sum('u');
        $totalD              = $items->sum('d');
        $totalTraffic        = $items->sum('total');
        $totalTransfer       = $items->sum('transfer_enable');
        $totalUsers          = $items->sum('user_count');

        return $this->ok([
            'list'    => $items,
            'summary' => [
                'u'               => $totalU,
                'd'               => $totalD,
                'total'           => $totalTraffic,
                'transfer_enable' => $totalTransfer,
                'user_count'      => $totalUsers,
            ],
        ]);
    }
}
