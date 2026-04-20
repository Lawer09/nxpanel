<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\NodePerformanceAggregated;
use App\Models\UserReportCount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'client_isp'     => 'nullable|string|max:255',
            'platform'       => 'nullable|string|max:100',
            'app_id'         => 'nullable|string|max:255',
            'app_version'    => 'nullable|string|max:50',
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
        if ($request->filled('client_isp')) {
            $query->where('client_isp', $request->input('client_isp'));
        }
        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }
        if ($request->filled('app_id')) {
            $query->where('app_id', $request->input('app_id'));
        }
        if ($request->filled('app_version')) {
            $query->where('app_version', $request->input('app_version'));
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
            'user_id'        => 'nullable|integer',
            'date_from'      => 'nullable|date',
            'date_to'        => 'nullable|date',
            'client_country' => 'nullable|string|max:2',
            'client_isp'     => 'nullable|string|max:255',
            'platform'       => 'nullable|string|max:100',
            'app_id'         => 'nullable|string|max:255',
            'app_version'    => 'nullable|string|max:50',
            'order_by'       => 'nullable|in:report_count,date,user_id',
            'order_dir'      => 'nullable|in:asc,desc',
            'page_size'      => 'nullable|integer|min:1|max:200',
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
        if ($request->filled('client_country')) {
            $query->where('client_country', $request->input('client_country'));
        }
        if ($request->filled('client_isp')) {
            $query->where('client_isp', $request->input('client_isp'));
        }
        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }
        if ($request->filled('app_id')) {
            $query->where('app_id', $request->input('app_id'));
        }
        if ($request->filled('app_version')) {
            $query->where('app_version', $request->input('app_version'));
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
            ->selectRaw('date, user_id, SUM(report_count) as total_reports, MAX(node_count) as max_nodes, MAX(client_country) as client_country, MAX(client_isp) as client_isp, MAX(platform) as platform, MAX(app_id) as app_id, MAX(app_version) as app_version')
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

    /**
     * App 版本分布统计
     *
     * GET /performance/versionDistribution
     */
    public function getVersionDistribution(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
            'app_id'    => 'nullable|string|max:255',
            'node_id'   => 'nullable|integer',
        ]);

        $query = NodePerformanceAggregated::query()
            ->selectRaw('app_id, app_version, SUM(total_count) as total_reports, COUNT(DISTINCT node_id) as node_count, ROUND(AVG(avg_success_rate), 2) as avg_success_rate, ROUND(AVG(avg_delay), 2) as avg_delay')
            ->whereNotNull('app_id')
            ->groupBy('app_id', 'app_version')
            ->orderByDesc('total_reports');

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }
        if ($request->filled('app_id')) {
            $query->where('app_id', $request->input('app_id'));
        }
        if ($request->filled('node_id')) {
            $query->where('node_id', $request->input('node_id'));
        }

        $data = $query->get();

        // 计算总上报数用于百分比
        $totalAll = $data->sum('total_reports');

        $items = $data->map(function ($row) use ($totalAll) {
            $row->percentage = $totalAll > 0 ? round($row->total_reports / $totalAll * 100, 2) : 0;
            return $row;
        });

        return $this->ok([
            'data'          => $items,
            'total_reports' => $totalAll,
        ]);
    }

    /**
     * 平台分布统计
     *
     * GET /performance/platformDistribution
     */
    public function getPlatformDistribution(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
            'app_id'    => 'nullable|string|max:255',
            'node_id'   => 'nullable|integer',
        ]);

        $query = NodePerformanceAggregated::query()
            ->selectRaw('platform, SUM(total_count) as total_reports, COUNT(DISTINCT node_id) as node_count, ROUND(AVG(avg_success_rate), 2) as avg_success_rate, ROUND(AVG(avg_delay), 2) as avg_delay')
            ->whereNotNull('platform')
            ->groupBy('platform')
            ->orderByDesc('total_reports');

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }
        if ($request->filled('app_id')) {
            $query->where('app_id', $request->input('app_id'));
        }
        if ($request->filled('node_id')) {
            $query->where('node_id', $request->input('node_id'));
        }

        $data = $query->get();

        $totalAll = $data->sum('total_reports');

        $items = $data->map(function ($row) use ($totalAll) {
            $row->percentage = $totalAll > 0 ? round($row->total_reports / $totalAll * 100, 2) : 0;
            return $row;
        });

        return $this->ok([
            'data'          => $items,
            'total_reports' => $totalAll,
        ]);
    }

    /**
     * 国家 / ISP 分布统计
     *
     * GET /performance/countryDistribution
     */
    public function getCountryDistribution(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
            'app_id'    => 'nullable|string|max:255',
            'node_id'   => 'nullable|integer',
        ]);

        $query = NodePerformanceAggregated::query()
            ->selectRaw('client_country, client_isp, SUM(total_count) as total_reports, COUNT(DISTINCT node_id) as node_count, ROUND(AVG(avg_success_rate), 2) as avg_success_rate, ROUND(AVG(avg_delay), 2) as avg_delay')
            ->whereNotNull('client_country')
            ->groupBy('client_country', 'client_isp')
            ->orderByDesc('total_reports');

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }
        if ($request->filled('app_id')) {
            $query->where('app_id', $request->input('app_id'));
        }
        if ($request->filled('node_id')) {
            $query->where('node_id', $request->input('node_id'));
        }

        $data = $query->get();

        $totalAll = $data->sum('total_reports');

        $items = $data->map(function ($row) use ($totalAll) {
            $row->percentage = $totalAll > 0 ? round($row->total_reports / $totalAll * 100, 2) : 0;
            return $row;
        });

        return $this->ok([
            'data'          => $items,
            'total_reports' => $totalAll,
        ]);
    }

    /**
     * 失败节点聚合统计（按国家、ISP、时间维度）
     *
     * 筛选 avg_success_rate 低于阈值的记录，聚合展示失败节点的地域/ISP分布
     *
     * GET /performance/failedNodes
     */
    public function getFailedNodes(Request $request): JsonResponse
    {
        $request->validate([
            'date_from'          => 'nullable|date',
            'date_to'            => 'nullable|date',
            'node_id'            => 'nullable|integer',
            'client_country'     => 'nullable|string|max:2',
            'client_isp'         => 'nullable|string|max:255',
            'app_id'             => 'nullable|string|max:255',
            'max_success_rate'   => 'nullable|numeric|min:0|max:100',
            'group_by'           => 'nullable|in:country,isp,node,time',
            'page_size'          => 'nullable|integer|min:1|max:200',
        ]);

        // 默认阈值：成功率 < 50% 视为失败
        $threshold = $request->input('max_success_rate', 50);
        $groupBy = $request->input('group_by', 'country');

        // 根据 group_by 决定聚合维度
        switch ($groupBy) {
            case 'isp':
                $selectRaw = 'client_country, client_isp, COUNT(*) as record_count, SUM(total_count) as total_reports, COUNT(DISTINCT node_id) as node_count, ROUND(AVG(avg_success_rate), 2) as avg_success_rate, ROUND(AVG(avg_delay), 2) as avg_delay';
                $groupFields = ['client_country', 'client_isp'];
                break;
            case 'node':
                $selectRaw = 'node_id, client_country, client_isp, COUNT(*) as record_count, SUM(total_count) as total_reports, ROUND(AVG(avg_success_rate), 2) as avg_success_rate, ROUND(AVG(avg_delay), 2) as avg_delay, MIN(CONCAT(date, " ", LPAD(hour, 2, "0"), ":", LPAD(minute, 2, "0"))) as first_seen, MAX(CONCAT(date, " ", LPAD(hour, 2, "0"), ":", LPAD(minute, 2, "0"))) as last_seen';
                $groupFields = ['node_id', 'client_country', 'client_isp'];
                break;
            case 'time':
                $selectRaw = 'date, hour, COUNT(DISTINCT node_id) as node_count, SUM(total_count) as total_reports, ROUND(AVG(avg_success_rate), 2) as avg_success_rate, ROUND(AVG(avg_delay), 2) as avg_delay';
                $groupFields = ['date', 'hour'];
                break;
            default: // country
                $selectRaw = 'client_country, COUNT(*) as record_count, SUM(total_count) as total_reports, COUNT(DISTINCT node_id) as node_count, ROUND(AVG(avg_success_rate), 2) as avg_success_rate, ROUND(AVG(avg_delay), 2) as avg_delay';
                $groupFields = ['client_country'];
                break;
        }

        $query = NodePerformanceAggregated::query()
            ->selectRaw($selectRaw)
            ->where('avg_success_rate', '<', $threshold)
            ->groupBy($groupFields)
            ->orderByDesc('total_reports');

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->input('date_to'));
        }
        if ($request->filled('node_id')) {
            $query->where('node_id', $request->input('node_id'));
        }
        if ($request->filled('client_country')) {
            $query->where('client_country', $request->input('client_country'));
        }
        if ($request->filled('client_isp')) {
            $query->where('client_isp', $request->input('client_isp'));
        }
        if ($request->filled('app_id')) {
            $query->where('app_id', $request->input('app_id'));
        }

        $pageSize = $request->input('page_size', 50);
        $data = $query->paginate($pageSize);

        return $this->ok([
            'data'              => $data->items(),
            'total'             => $data->total(),
            'page'              => $data->currentPage(),
            'pageSize'          => $data->perPage(),
            'threshold'         => $threshold,
            'group_by'          => $groupBy,
        ]);
    }
}
