<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\NodePerformanceAggregated;
use App\Models\UserReportCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerformanceController extends Controller
{
    /**
     * 查询节点性能聚合数据
     *
     * GET /performance/aggregated
     */
    public function getAggregated(Request $request): JsonResponse
    {
        $request->validate([
            'node_id'        => 'nullable|integer',
            'date_from'      => 'nullable|date',
            'date_to'        => 'nullable|date',
            'client_country' => 'nullable|string|max:2',
            'platform'       => 'nullable|string|max:100',
            'page_size'      => 'nullable|integer|min:1|max:200',
        ]);

        $query = NodePerformanceAggregated::query()->orderByDesc('date')->orderByDesc('hour')->orderByDesc('minute');

        if ($request->filled('node_id')) {
            $query->where('node_id', $request->input('node_id'));
        }
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }
        if ($request->filled('client_country')) {
            $query->where('client_country', $request->input('client_country'));
        }
        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }

        $pageSize = $request->input('page_size', 50);
        $data = $query->paginate($pageSize);

        return $this->ok([
            'data'     => $data->items(),
            'total'    => $data->total(),
            'page'     => $data->currentPage(),
            'pageSize' => $data->perPage(),
        ]);
    }

    /**
     * 查询用户上报次数统计
     *
     * GET /performance/userReportCount
     */
    public function getUserReportCount(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'   => 'nullable|integer',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
            'platform'  => 'nullable|string|max:100',
            'app_id'    => 'nullable|string|max:255',
            'order_by'  => 'nullable|in:report_count,date,user_id',
            'order_dir' => 'nullable|in:asc,desc',
            'page_size' => 'nullable|integer|min:1|max:200',
        ]);

        $query = UserReportCount::query();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }
        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }
        if ($request->filled('app_id')) {
            $query->where('app_id', $request->input('app_id'));
        }

        $orderBy = $request->input('order_by', 'date');
        $orderDir = $request->input('order_dir', 'desc');

        if ($orderBy === 'date') {
            $query->orderBy('date', $orderDir)->orderByDesc('hour')->orderByDesc('minute');
        } else {
            $query->orderBy($orderBy, $orderDir);
        }

        $pageSize = $request->input('page_size', 50);
        $data = $query->paginate($pageSize);

        return $this->ok([
            'data'     => $data->items(),
            'total'    => $data->total(),
            'page'     => $data->currentPage(),
            'pageSize' => $data->perPage(),
        ]);
    }

    /**
     * 用户上报次数汇总（按天）
     *
     * GET /performance/userReportDaily
     */
    public function getUserReportDaily(Request $request): JsonResponse
    {
        $request->validate([
            'user_id'   => 'nullable|integer',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
            'page_size' => 'nullable|integer|min:1|max:200',
        ]);

        $query = UserReportCount::query()
            ->selectRaw('date, user_id, SUM(report_count) as total_reports, MAX(node_count) as max_nodes, MAX(platform) as platform, MAX(app_id) as app_id, MAX(app_version) as app_version')
            ->groupBy('date', 'user_id')
            ->orderByDesc('date');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }
        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }

        $pageSize = $request->input('page_size', 50);
        $data = $query->paginate($pageSize);

        return $this->ok([
            'data'     => $data->items(),
            'total'    => $data->total(),
            'page'     => $data->currentPage(),
            'pageSize' => $data->perPage(),
        ]);
    }
}
