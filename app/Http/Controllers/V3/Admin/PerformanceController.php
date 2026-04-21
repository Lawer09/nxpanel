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
     *
     * 支持 group_by 参数按不同维度聚合统计成功率和延时：
     *   - 不传 group_by：返回原始明细（分页）
     *   - node：按节点聚合
     *   - country：按国家聚合
     *   - isp：按国家+ISP 聚合
     *   - platform：按平台聚合
     *   - app_version：按 app_id+app_version 聚合
     *   - date：按天聚合
     *   - hour：按天+小时聚合
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
            'group_by'       => 'nullable|in:node,country,isp,platform,app_version,date,hour',
            'page_size'      => 'nullable|integer|min:1|max:200',
        ]);

        $groupBy = $request->input('group_by');

        // 公共筛选条件
        $applyFilters = function ($query) use ($request) {
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
            return $query;
        };

        // 无 group_by 时返回原始明细
        if (!$groupBy) {
            $query = NodePerformanceAggregated::query()
                ->orderByDesc('date')->orderByDesc('hour')->orderByDesc('minute');
            $applyFilters($query);

            $pageSize = $request->input('page_size', 50);
            $data = $query->paginate($pageSize);

            return $this->ok([
                'data'     => $data->items(),
                'total'    => $data->total(),
                'page'     => $data->currentPage(),
                'pageSize' => $data->perPage(),
            ]);
        }

        // 按维度聚合：加权平均成功率 / 加权平均延时
        $weightedAvg = 'ROUND(SUM(avg_success_rate * total_count) / NULLIF(SUM(total_count), 0), 2) as avg_success_rate, '
            . 'ROUND(SUM(avg_delay * total_count) / NULLIF(SUM(total_count), 0), 2) as avg_delay, '
            . 'SUM(total_count) as total_count';

        switch ($groupBy) {
            case 'node':
                $selectRaw = "node_id, {$weightedAvg}, COUNT(*) as record_count";
                $groupFields = ['node_id'];
                $orderBy = 'total_count';
                break;
            case 'country':
                $selectRaw = "client_country, {$weightedAvg}, COUNT(DISTINCT node_id) as node_count, COUNT(*) as record_count";
                $groupFields = ['client_country'];
                $orderBy = 'total_count';
                break;
            case 'isp':
                $selectRaw = "client_country, client_isp, {$weightedAvg}, COUNT(DISTINCT node_id) as node_count, COUNT(*) as record_count";
                $groupFields = ['client_country', 'client_isp'];
                $orderBy = 'total_count';
                break;
            case 'platform':
                $selectRaw = "platform, {$weightedAvg}, COUNT(DISTINCT node_id) as node_count, COUNT(*) as record_count";
                $groupFields = ['platform'];
                $orderBy = 'total_count';
                break;
            case 'app_version':
                $selectRaw = "app_id, app_version, {$weightedAvg}, COUNT(DISTINCT node_id) as node_count, COUNT(*) as record_count";
                $groupFields = ['app_id', 'app_version'];
                $orderBy = 'total_count';
                break;
            case 'date':
                $selectRaw = "date, {$weightedAvg}, COUNT(DISTINCT node_id) as node_count, COUNT(*) as record_count";
                $groupFields = ['date'];
                $orderBy = 'date';
                break;
            case 'hour':
                $selectRaw = "date, hour, {$weightedAvg}, COUNT(DISTINCT node_id) as node_count, COUNT(*) as record_count";
                $groupFields = ['date', 'hour'];
                $orderBy = 'date';
                break;
            default:
                $selectRaw = "{$weightedAvg}, COUNT(*) as record_count";
                $groupFields = [];
                $orderBy = 'total_count';
                break;
        }

        $query = NodePerformanceAggregated::query()
            ->selectRaw($selectRaw)
            ->groupBy($groupFields);

        $applyFilters($query);

        if (in_array($groupBy, ['date', 'hour'])) {
            $query->orderByDesc($orderBy);
            if ($groupBy === 'hour') {
                $query->orderByDesc('hour');
            }
        } else {
            $query->orderByDesc($orderBy);
        }

        $pageSize = $request->input('page_size', 50);
        $data = $query->paginate($pageSize);

        return $this->ok([
            'data'     => $data->items(),
            'total'    => $data->total(),
            'page'     => $data->currentPage(),
            'pageSize' => $data->perPage(),
            'group_by' => $groupBy,
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

    /**
     * 用户留存分析（留存矩阵）
     *
     * 以每天的活跃用户为一个 cohort，计算 day+1, day+3, day+7, day+14, day+30 的留存率
     *
     * GET /performance/retention
     */
    public function getRetention(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
            'app_id'    => 'nullable|string|max:255',
            'platform'  => 'nullable|string|max:100',
        ]);

        $dateFrom = $request->input('date_from', now()->subDays(30)->toDateString());
        $dateTo = $request->input('date_to', now()->subDay()->toDateString());

        // 留存天数
        $retentionDays = [1, 3, 7, 14, 30];

        // 构建基础查询条件
        $baseConditions = function ($query) use ($request) {
            if ($request->filled('app_id')) {
                $query->where('app_id', $request->input('app_id'));
            }
            if ($request->filled('platform')) {
                $query->where('platform', $request->input('platform'));
            }
        };

        // 获取每天的活跃用户集合
        $cohorts = DB::table('v3_user_report_count')
            ->selectRaw('date, COUNT(DISTINCT user_id) as active_users')
            ->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo)
            ->where($baseConditions)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $result = [];

        foreach ($cohorts as $cohort) {
            $cohortDate = $cohort->date;
            $row = [
                'date'         => $cohortDate,
                'active_users' => $cohort->active_users,
                'retention'    => [],
            ];

            foreach ($retentionDays as $day) {
                $targetDate = date('Y-m-d', strtotime($cohortDate . " +{$day} days"));

                // 如果目标日期超过今天，跳过
                if ($targetDate > now()->toDateString()) {
                    $row['retention']["day_{$day}"] = null;
                    continue;
                }

                // 计算 cohort 日活跃用户在 target 日也活跃的数量
                $retained = DB::table('v3_user_report_count as a')
                    ->join('v3_user_report_count as b', 'a.user_id', '=', 'b.user_id')
                    ->where('a.date', $cohortDate)
                    ->where('b.date', $targetDate)
                    ->where(function ($q) use ($request) {
                        if ($request->filled('app_id')) {
                            $q->where('a.app_id', $request->input('app_id'));
                        }
                        if ($request->filled('platform')) {
                            $q->where('a.platform', $request->input('platform'));
                        }
                    })
                    ->distinct('a.user_id')
                    ->count('a.user_id');

                $rate = $cohort->active_users > 0
                    ? round($retained / $cohort->active_users * 100, 2)
                    : 0;

                $row['retention']["day_{$day}"] = [
                    'count' => $retained,
                    'rate'  => $rate,
                ];
            }

            $result[] = $row;
        }

        return $this->ok([
            'data'           => $result,
            'date_from'      => $dateFrom,
            'date_to'        => $dateTo,
            'retention_days' => $retentionDays,
        ]);
    }

    /**
     * 活跃用户趋势（DAU / WAU / MAU）
     *
     * GET /performance/activeUsers
     */
    public function getActiveUsers(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
            'app_id'    => 'nullable|string|max:255',
            'platform'  => 'nullable|string|max:100',
            'granularity' => 'nullable|in:day,week,month',
        ]);

        $dateFrom = $request->input('date_from', now()->subDays(30)->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());
        $granularity = $request->input('granularity', 'day');

        $baseQuery = DB::table('v3_user_report_count')
            ->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo);

        if ($request->filled('app_id')) {
            $baseQuery->where('app_id', $request->input('app_id'));
        }
        if ($request->filled('platform')) {
            $baseQuery->where('platform', $request->input('platform'));
        }

        switch ($granularity) {
            case 'week':
                $data = (clone $baseQuery)
                    ->selectRaw('YEARWEEK(date, 1) as period, MIN(date) as period_start, MAX(date) as period_end, COUNT(DISTINCT user_id) as active_users, SUM(report_count) as total_reports')
                    ->groupByRaw('YEARWEEK(date, 1)')
                    ->orderBy('period')
                    ->get();
                break;
            case 'month':
                $data = (clone $baseQuery)
                    ->selectRaw('DATE_FORMAT(date, "%Y-%m") as period, MIN(date) as period_start, MAX(date) as period_end, COUNT(DISTINCT user_id) as active_users, SUM(report_count) as total_reports')
                    ->groupByRaw('DATE_FORMAT(date, "%Y-%m")')
                    ->orderBy('period')
                    ->get();
                break;
            default: // day
                $data = (clone $baseQuery)
                    ->selectRaw('date as period, date as period_start, date as period_end, COUNT(DISTINCT user_id) as active_users, SUM(report_count) as total_reports')
                    ->groupBy('date')
                    ->orderBy('date')
                    ->get();
                break;
        }

        return $this->ok([
            'data'        => $data,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
            'granularity' => $granularity,
        ]);
    }

    /**
     * 活跃用户概览（当前 DAU / WAU / MAU）
     *
     * GET /performance/activeUsersSummary
     */
    public function getActiveUsersSummary(Request $request): JsonResponse
    {
        $request->validate([
            'app_id'   => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:100',
        ]);

        $today = now()->toDateString();

        $baseQuery = DB::table('v3_user_report_count');

        if ($request->filled('app_id')) {
            $baseQuery->where('app_id', $request->input('app_id'));
        }
        if ($request->filled('platform')) {
            $baseQuery->where('platform', $request->input('platform'));
        }

        // DAU - 今日活跃
        $dau = (clone $baseQuery)
            ->where('date', $today)
            ->distinct('user_id')
            ->count('user_id');

        // 昨日 DAU（用于环比）
        $dauYesterday = (clone $baseQuery)
            ->where('date', now()->subDay()->toDateString())
            ->distinct('user_id')
            ->count('user_id');

        // WAU - 最近 7 天活跃
        $wau = (clone $baseQuery)
            ->where('date', '>=', now()->subDays(6)->toDateString())
            ->where('date', '<=', $today)
            ->distinct('user_id')
            ->count('user_id');

        // 上周 WAU
        $wauLastWeek = (clone $baseQuery)
            ->where('date', '>=', now()->subDays(13)->toDateString())
            ->where('date', '<=', now()->subDays(7)->toDateString())
            ->distinct('user_id')
            ->count('user_id');

        // MAU - 最近 30 天活跃
        $mau = (clone $baseQuery)
            ->where('date', '>=', now()->subDays(29)->toDateString())
            ->where('date', '<=', $today)
            ->distinct('user_id')
            ->count('user_id');

        // 上月 MAU
        $mauLastMonth = (clone $baseQuery)
            ->where('date', '>=', now()->subDays(59)->toDateString())
            ->where('date', '<=', now()->subDays(30)->toDateString())
            ->distinct('user_id')
            ->count('user_id');

        return $this->ok([
            'dau' => [
                'count'     => $dau,
                'yesterday' => $dauYesterday,
                'change'    => $dauYesterday > 0 ? round(($dau - $dauYesterday) / $dauYesterday * 100, 2) : 0,
            ],
            'wau' => [
                'count'     => $wau,
                'last_week' => $wauLastWeek,
                'change'    => $wauLastWeek > 0 ? round(($wau - $wauLastWeek) / $wauLastWeek * 100, 2) : 0,
            ],
            'mau' => [
                'count'      => $mau,
                'last_month' => $mauLastMonth,
                'change'     => $mauLastMonth > 0 ? round(($mau - $mauLastMonth) / $mauLastMonth * 100, 2) : 0,
            ],
        ]);
    }
}
