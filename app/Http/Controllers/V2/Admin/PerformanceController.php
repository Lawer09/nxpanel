<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\NodePerformanceReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerformanceController extends Controller
{
    /**
     * 上报记录列表（分页）
     *
     * GET /admin/performance/fetch
     * 参数：node_id, user_id, platform, client_country, start_at, end_at, page, page_size
     */
    public function fetch(Request $request)
    {
        $request->validate([
            'node_id'        => 'nullable|integer',
            'user_id'        => 'nullable|integer',
            'platform'       => 'nullable|string',
            'client_country' => 'nullable|string|max:2',
            'start_at'       => 'nullable|integer',
            'end_at'         => 'nullable|integer',
            'page_size'      => 'nullable|integer|min:1|max:500',
        ]);

        $query = NodePerformanceReport::with(['user:id,email'])
            ->select([
                'id', 'user_id', 'node_id', 'delay', 'success_rate',
                'client_ip', 'client_country', 'client_city', 'client_isp',
                'platform', 'app_version', 'created_at',
            ]);

        if ($request->filled('node_id')) {
            $query->where('node_id', $request->integer('node_id'));
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }
        if ($request->filled('client_country')) {
            $query->where('client_country', strtoupper($request->input('client_country')));
        }
        if ($request->filled('start_at')) {
            $query->where('created_at', '>=', date('Y-m-d H:i:s', $request->integer('start_at')));
        }
        if ($request->filled('end_at')) {
            $query->where('created_at', '<=', date('Y-m-d H:i:s', $request->integer('end_at')));
        }

        $pageSize = $request->integer('page_size', 15);
        $current  = $request->integer('page', 1);
        $result   = $query->orderByDesc('created_at')->paginate($pageSize, ['*'], 'page', $current);

        return $this->ok([
            'data'     => $result->items(),
            'total'    => $result->total(),
            'pageSize' => $result->perPage(),
            'page'     => $result->currentPage(),
        ]);
    }

    /**
     * 按节点聚合统计
     *
     * GET /admin/performance/nodeStats
     * 参数：days (默认7), node_id
     */
    public function nodeStats(Request $request)
    {
        $request->validate([
            'days'    => 'nullable|integer|min:1|max:90',
            'node_id' => 'nullable|integer',
        ]);

        $days      = $request->integer('days', 7);
        $startDate = now()->subDays($days)->startOfDay();

        $query = NodePerformanceReport::query()
            ->where('created_at', '>=', $startDate)
            ->select(DB::raw('
                node_id,
                COUNT(*)                    AS report_count,
                ROUND(AVG(delay), 1)        AS avg_delay,
                MIN(delay)                  AS min_delay,
                MAX(delay)                  AS max_delay,
                ROUND(AVG(success_rate), 1) AS avg_success_rate,
                COUNT(DISTINCT user_id)     AS unique_users
            '))
            ->groupBy('node_id')
            ->orderByDesc('report_count');

        if ($request->filled('node_id')) {
            $query->where('node_id', $request->integer('node_id'));
        }

        return $this->ok([
            'period_days' => $days,
            'data'        => $query->get(),
        ]);
    }

    /**
     * 节点性能时间趋势（按小时或按天）
     *
     * GET /admin/performance/trend
     * 参数：node_id (required), days (默认7), granularity (hour|day，默认day)
     */
    public function trend(Request $request)
    {
        $request->validate([
            'node_id'     => 'required|integer',
            'days'        => 'nullable|integer|min:1|max:90',
            'granularity' => 'nullable|in:hour,day',
        ]);

        $nodeId      = $request->integer('node_id');
        $days        = $request->integer('days', 7);
        $granularity = $request->input('granularity', 'day');
        $startDate   = now()->subDays($days)->startOfDay();

        $dateFormat = $granularity === 'hour' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d';

        $data = NodePerformanceReport::where('node_id', $nodeId)
            ->where('created_at', '>=', $startDate)
            ->select(DB::raw("
                DATE_FORMAT(created_at, '{$dateFormat}')  AS time_slot,
                ROUND(AVG(delay), 1)                      AS avg_delay,
                MIN(delay)                                AS min_delay,
                MAX(delay)                                AS max_delay,
                ROUND(AVG(success_rate), 1)               AS avg_success_rate,
                COUNT(*)                                  AS report_count
            "))
            ->groupBy('time_slot')
            ->orderBy('time_slot')
            ->get();

        return $this->ok([
            'node_id'     => $nodeId,
            'period_days' => $days,
            'granularity' => $granularity,
            'data'        => $data,
        ]);
    }

    /**
     * 客户端地域分布
     *
     * GET /admin/performance/geoDistribution
     * 参数：node_id, days (默认7)
     */
    public function geoDistribution(Request $request)
    {
        $request->validate([
            'node_id' => 'nullable|integer',
            'days'    => 'nullable|integer|min:1|max:90',
        ]);

        $days      = $request->integer('days', 7);
        $startDate = now()->subDays($days)->startOfDay();

        $query = NodePerformanceReport::where('created_at', '>=', $startDate)
            ->select(DB::raw('
                client_country,
                COUNT(*)                    AS report_count,
                COUNT(DISTINCT user_id)     AS unique_users,
                ROUND(AVG(delay), 1)        AS avg_delay,
                ROUND(AVG(success_rate), 1) AS avg_success_rate
            '))
            ->groupBy('client_country')
            ->orderByDesc('report_count');

        if ($request->filled('node_id')) {
            $query->where('node_id', $request->integer('node_id'));
        }

        return $this->ok([
            'period_days' => $days,
            'data'        => $query->get(),
        ]);
    }

    /**
     * 平台分布统计
     *
     * GET /admin/performance/platformStats
     * 参数：node_id, days (默认7)
     */
    public function platformStats(Request $request)
    {
        $request->validate([
            'node_id' => 'nullable|integer',
            'days'    => 'nullable|integer|min:1|max:90',
        ]);

        $days      = $request->integer('days', 7);
        $startDate = now()->subDays($days)->startOfDay();

        $query = NodePerformanceReport::where('created_at', '>=', $startDate)
            ->select(DB::raw('
                platform,
                COUNT(*)                    AS report_count,
                COUNT(DISTINCT user_id)     AS unique_users,
                ROUND(AVG(delay), 1)        AS avg_delay,
                ROUND(AVG(success_rate), 1) AS avg_success_rate
            '))
            ->groupBy('platform')
            ->orderByDesc('report_count');

        if ($request->filled('node_id')) {
            $query->where('node_id', $request->integer('node_id'));
        }

        return $this->ok([
            'period_days' => $days,
            'data'        => $query->get(),
        ]);
    }
}
