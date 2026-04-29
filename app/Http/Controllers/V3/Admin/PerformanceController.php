<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\NodePerformanceAggregated;
use App\Models\Server;
use App\Models\UserReportCount;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\CamelizeResource;

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
            'nodeId'         => 'nullable|integer',
            'dateFrom'       => 'nullable|date',
            'dateTo'         => 'nullable|date',
            'clientCountry'  => 'nullable|string|max:2',
            'clientIsp'      => 'nullable|string|max:255',
            'platform'       => 'nullable|string|max:100',
            'appId'          => 'nullable|string|max:255',
            'appVersion'     => 'nullable|string|max:50',
            'groupBy'        => 'nullable|in:node,country,isp,platform,app_version,date,hour',
            'pageSize'       => 'nullable|integer|min:1|max:200',
        ]);

        $groupBy = $request->input('groupBy');

        // 公共筛选条件
        $applyFilters = function ($query) use ($request) {
            if ($request->filled('nodeId')) {
                $query->where('node_id', $request->input('nodeId'));
            }
            if ($request->filled('dateFrom')) {
                $query->where('date', '>=', $request->input('dateFrom'));
            }
            if ($request->filled('dateTo')) {
                $query->where('date', '<=', $request->input('dateTo'));
            }
            if ($request->filled('clientCountry')) {
                $query->where('client_country', $request->input('clientCountry'));
            }
            if ($request->filled('clientIsp')) {
                $query->where('client_isp', $request->input('clientIsp'));
            }
            if ($request->filled('platform')) {
                $query->where('platform', $request->input('platform'));
            }
            if ($request->filled('appId')) {
                $query->where('app_id', $request->input('appId'));
            }
            if ($request->filled('appVersion')) {
                $query->where('app_version', $request->input('appVersion'));
            }
            return $query;
        };

        // 无 group_by 时返回原始明细
        if (!$groupBy) {
            $query = NodePerformanceAggregated::query()
                ->orderByDesc('date')->orderByDesc('hour')->orderByDesc('minute');
            $applyFilters($query);

            $pageSize = $request->input('pageSize', 50);
            $data = $query->paginate($pageSize);

            return $this->ok([
                'data'     => CamelizeResource::collection($data->items()),
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

        $pageSize = $request->input('pageSize', 50);
        $data = $query->paginate($pageSize);

        // 按节点聚合时补充节点名称和类型
        $items = $data->items();
        if ($groupBy === 'node' && !empty($items)) {
            $nodeIds = collect($items)->map(fn($row) => $row->node_id ?? data_get($row, 'node_id'))->unique()->filter()->values();
            $servers = Server::whereIn('id', $nodeIds)->get(['id', 'name', 'type'])->keyBy('id');
            $items = collect($items)->map(function ($row) use ($servers) {
                $nodeId = $row->node_id ?? data_get($row, 'node_id');
                $server = $servers->get($nodeId);
                $arr = $row instanceof \Illuminate\Database\Eloquent\Model ? $row->toArray() : (array) $row;
                $arr['node_name'] = $server?->name ?? "Server {$nodeId}";
                $arr['node_type'] = $server?->type;
                return $arr;
            })->toArray();
        }

        return $this->ok([
            'data'     => CamelizeResource::collection($items),
            'total'    => $data->total(),
            'page'     => $data->currentPage(),
            'pageSize' => $data->perPage(),
            'groupBy'  => $groupBy,
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
            'userId'         => 'nullable|integer',
            'dateFrom'       => 'nullable|date',
            'dateTo'         => 'nullable|date',
            'clientCountry'  => 'nullable|string|max:2',
            'clientIsp'      => 'nullable|string|max:255',
            'platform'       => 'nullable|string|max:100',
            'appId'          => 'nullable|string|max:255',
            'appVersion'     => 'nullable|string|max:50',
            'orderBy'        => 'nullable|in:report_count,date,user_id',
            'orderDir'       => 'nullable|in:asc,desc',
            'pageSize'       => 'nullable|integer|min:1|max:200',
        ]);

        $query = UserReportCount::query();

        if ($request->filled('userId')) {
            $query->where('user_id', $request->input('userId'));
        }
        if ($request->filled('dateFrom')) {
            $query->where('date', '>=', $request->input('dateFrom'));
        }
        if ($request->filled('dateTo')) {
            $query->where('date', '<=', $request->input('dateTo'));
        }
        if ($request->filled('clientCountry')) {
            $query->where('client_country', $request->input('clientCountry'));
        }
        if ($request->filled('clientIsp')) {
            $query->where('client_isp', $request->input('clientIsp'));
        }
        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }
        if ($request->filled('appId')) {
            $query->where('app_id', $request->input('appId'));
        }
        if ($request->filled('appVersion')) {
            $query->where('app_version', $request->input('appVersion'));
        }

        $orderBy = $request->input('orderBy', 'date');
        $orderDir = $request->input('orderDir', 'desc');

        if ($orderBy === 'date') {
            $query->orderBy('date', $orderDir)->orderByDesc('hour')->orderByDesc('minute');
        } else {
            $query->orderBy($orderBy, $orderDir);
        }

        $pageSize = $request->input('pageSize', 50);
        $data = $query->paginate($pageSize);

        return $this->ok([
            'data'     => CamelizeResource::collection($data->items()),
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
            'userId'   => 'nullable|integer',
            'dateFrom' => 'nullable|date',
            'dateTo'   => 'nullable|date',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ]);

        $query = UserReportCount::query()
            ->selectRaw('date, user_id, SUM(report_count) as total_reports, MAX(node_count) as max_nodes, MAX(client_country) as client_country, MAX(client_isp) as client_isp, MAX(platform) as platform, MAX(app_id) as app_id, MAX(app_version) as app_version')
            ->groupBy('date', 'user_id')
            ->orderByDesc('date');

        if ($request->filled('userId')) {
            $query->where('user_id', $request->input('userId'));
        }
        if ($request->filled('dateFrom')) {
            $query->where('date', '>=', $request->input('dateFrom'));
        }
        if ($request->filled('dateTo')) {
            $query->where('date', '<=', $request->input('dateTo'));
        }

        $pageSize = $request->input('pageSize', 50);
        $data = $query->paginate($pageSize);

        return $this->ok([
            'data'     => CamelizeResource::collection($data->items()),
            'total'    => $data->total(),
            'page'     => $data->currentPage(),
            'pageSize' => $data->perPage(),
        ]);
    }

    /**
     * 节点探测错误分析
     *
     * GET /performance/probeErrors
     */
    public function getProbeErrors(Request $request): JsonResponse
    {
        $request->validate([
            'nodeId'        => 'nullable|integer',
            'dateFrom'      => 'nullable|date',
            'dateTo'        => 'nullable|date',
            'clientCountry' => 'nullable|string|max:2',
            'platform'      => 'nullable|string|max:100',
            'appId'         => 'nullable|string|max:255',
            'appVersion'    => 'nullable|string|max:50',
            'probeStage'    => 'nullable|in:node_connect,post_connect_probe,tunnel_establish',
            'status'        => 'nullable|in:success,failed,timeout,cancelled',
            'errorCode'     => 'nullable|string|max:64',
            'groupBy'       => 'nullable|in:node,error_code,stage,status,stage_error',
            'includeExternal' => 'nullable|boolean',
            'pageSize'      => 'nullable|integer|min:1|max:200',
        ]);

        $groupBy = $request->input('groupBy', 'stage_error');
        $pageSize = $request->input('pageSize', 50);
        $includeExternal = $request->boolean('includeExternal', false);

        $query = DB::table('v2_node_probe_aggregated');

        if (!$includeExternal) {
            $query->where('node_id', '>', 0);
        }

        if ($request->filled('nodeId')) {
            $query->where('node_id', $request->input('nodeId'));
        }
        if ($request->filled('dateFrom')) {
            $query->where('date', '>=', $request->input('dateFrom'));
        }
        if ($request->filled('dateTo')) {
            $query->where('date', '<=', $request->input('dateTo'));
        }
        if ($request->filled('clientCountry')) {
            $query->where('client_country', $request->input('clientCountry'));
        }
        if ($request->filled('platform')) {
            $query->where('platform', $request->input('platform'));
        }
        if ($request->filled('appId')) {
            $query->where('app_id', $request->input('appId'));
        }
        if ($request->filled('appVersion')) {
            $query->where('app_version', $request->input('appVersion'));
        }
        if ($request->filled('probeStage')) {
            $probeStage = $request->input('probeStage') === 'tunnel_establish'
                ? 'node_connect'
                : $request->input('probeStage');
            $query->where('probe_stage', $probeStage);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('errorCode')) {
            $query->where('error_code', $request->input('errorCode'));
        }

        $dimensionMap = [
            'node' => ['node_id'],
            'error_code' => ['error_code'],
            'stage' => ['probe_stage'],
            'status' => ['status'],
            'stage_error' => ['probe_stage', 'error_code'],
        ];
        $dimensions = $dimensionMap[$groupBy] ?? $dimensionMap['stage_error'];

        $selectRaw = implode(', ', $dimensions) . ', SUM(total_count) as total_count';
        $data = $query->selectRaw($selectRaw)
            ->groupBy($dimensions)
            ->orderByDesc('total_count')
            ->paginate($pageSize);

        return $this->ok([
            'data' => CamelizeResource::collection($data->items()),
            'total' => $data->total(),
            'page' => $data->currentPage(),
            'pageSize' => $data->perPage(),
            'groupBy' => $groupBy,
        ]);
    }

    /**
     * 节点失败率排行（success/failed 合并口径）
     *
     * GET /performance/nodeFailureRank
     */
    public function getNodeFailureRank(Request $request): JsonResponse
    {
        $request->validate([
            'dateFrom'      => 'nullable|date',
            'dateTo'        => 'nullable|date',
            'clientCountry' => 'nullable|string|max:2',
            'platform'      => 'nullable|string|max:100',
            'appId'         => 'nullable|string|max:255',
            'appVersion'    => 'nullable|string|max:50',
            'probeStage'    => 'nullable|in:node_connect,post_connect_probe,tunnel_establish',
            'minTotal'      => 'nullable|integer|min:1|max:1000000',
            'includeExternal' => 'nullable|boolean',
            'pageSize'      => 'nullable|integer|min:1|max:200',
        ]);

        $pageSize = (int) $request->input('pageSize', 50);
        $minTotal = (int) $request->input('minTotal', 20);
        $includeExternal = $request->boolean('includeExternal', false);

        $query = DB::table('v2_node_probe_aggregated as p')
            ->leftJoin('v2_server as s', 's.id', '=', 'p.node_id')
            ->whereIn('p.status', ['success', 'failed'])
            ->selectRaw('p.node_id')
            ->selectRaw('MAX(p.node_ip) as node_ip')
            ->selectRaw('COALESCE(MAX(s.name), MAX(p.node_ip), CONCAT("Server ", p.node_id)) as node_name')
            ->selectRaw("SUM(CASE WHEN p.status = 'success' THEN p.total_count ELSE 0 END) as success_count")
            ->selectRaw("SUM(CASE WHEN p.status = 'failed' THEN p.total_count ELSE 0 END) as failed_count")
            ->selectRaw('SUM(p.total_count) as total_count')
            ->selectRaw('ROUND(100 * SUM(CASE WHEN p.status = \'failed\' THEN p.total_count ELSE 0 END) / NULLIF(SUM(p.total_count), 0), 2) as failure_rate')
            ->groupBy('p.node_id');

        if (!$includeExternal) {
            $query->where('p.node_id', '>', 0);
        }

        if ($request->filled('dateFrom')) {
            $query->where('p.date', '>=', $request->input('dateFrom'));
        }
        if ($request->filled('dateTo')) {
            $query->where('p.date', '<=', $request->input('dateTo'));
        }
        if ($request->filled('clientCountry')) {
            $query->where('p.client_country', $request->input('clientCountry'));
        }
        if ($request->filled('platform')) {
            $query->where('p.platform', $request->input('platform'));
        }
        if ($request->filled('appId')) {
            $query->where('p.app_id', $request->input('appId'));
        }
        if ($request->filled('appVersion')) {
            $query->where('p.app_version', $request->input('appVersion'));
        }
        if ($request->filled('probeStage')) {
            $probeStage = $request->input('probeStage') === 'tunnel_establish'
                ? 'node_connect'
                : $request->input('probeStage');
            $query->where('p.probe_stage', $probeStage);
        }

        $query->havingRaw('SUM(p.total_count) >= ?', [$minTotal])
            ->orderByDesc('failure_rate')
            ->orderByDesc('failed_count');

        $data = $query->paginate($pageSize);

        return $this->ok([
            'data' => CamelizeResource::collection($data->items()),
            'total' => $data->total(),
            'page' => $data->currentPage(),
            'pageSize' => $data->perPage(),
            'minTotal' => $minTotal,
        ]);
    }

    /**
     * 伪成功识别：node_connect 成功，但 post_connect_probe 失败
     *
     * GET /performance/pseudoSuccess
     */
    public function getPseudoSuccess(Request $request): JsonResponse
    {
        $request->validate([
            'dateFrom'      => 'nullable|date',
            'dateTo'        => 'nullable|date',
            'clientCountry' => 'nullable|string|max:2',
            'platform'      => 'nullable|string|max:100',
            'appId'         => 'nullable|string|max:255',
            'appVersion'    => 'nullable|string|max:50',
            'minConnected'  => 'nullable|integer|min:1|max:1000000',
            'includeExternal' => 'nullable|boolean',
            'pageSize'      => 'nullable|integer|min:1|max:200',
        ]);

        $pageSize = (int) $request->input('pageSize', 50);
        $minConnected = (int) $request->input('minConnected', 20);
        $includeExternal = $request->boolean('includeExternal', false);

        $nodeConnectSuccess = DB::table('v2_node_probe_aggregated')
            ->selectRaw('node_id, MAX(node_ip) as node_ip, SUM(total_count) as node_connect_success_count')
            ->where('probe_stage', 'node_connect')
            ->where('status', 'success')
            ->groupBy('node_id');

        $postConnectFailed = DB::table('v2_node_probe_aggregated')
            ->selectRaw('node_id, SUM(total_count) as post_connect_failed_count')
            ->where('probe_stage', 'post_connect_probe')
            ->where('status', 'failed')
            ->groupBy('node_id');

        if (!$includeExternal) {
            $nodeConnectSuccess->where('node_id', '>', 0);
            $postConnectFailed->where('node_id', '>', 0);
        }

        $applyFilters = function ($query, string $alias) use ($request) {
            if ($request->filled('dateFrom')) {
                $query->where("{$alias}.date", '>=', $request->input('dateFrom'));
            }
            if ($request->filled('dateTo')) {
                $query->where("{$alias}.date", '<=', $request->input('dateTo'));
            }
            if ($request->filled('clientCountry')) {
                $query->where("{$alias}.client_country", $request->input('clientCountry'));
            }
            if ($request->filled('platform')) {
                $query->where("{$alias}.platform", $request->input('platform'));
            }
            if ($request->filled('appId')) {
                $query->where("{$alias}.app_id", $request->input('appId'));
            }
            if ($request->filled('appVersion')) {
                $query->where("{$alias}.app_version", $request->input('appVersion'));
            }
            return $query;
        };

        $nodeConnectSuccess = $applyFilters($nodeConnectSuccess, 'v2_node_probe_aggregated');
        $postConnectFailed = $applyFilters($postConnectFailed, 'v2_node_probe_aggregated');

        $query = DB::query()
            ->fromSub($nodeConnectSuccess, 'a')
            ->leftJoinSub($postConnectFailed, 'b', function ($join) {
                $join->on('a.node_id', '=', 'b.node_id');
            })
            ->leftJoin('v2_server as s', 's.id', '=', 'a.node_id')
            ->selectRaw('a.node_id, a.node_ip')
            ->selectRaw('COALESCE(s.name, a.node_ip, CONCAT("Server ", a.node_id)) as node_name')
            ->selectRaw('a.node_connect_success_count')
            ->selectRaw('COALESCE(b.post_connect_failed_count, 0) as post_connect_failed_count')
            ->selectRaw('ROUND(100 * COALESCE(b.post_connect_failed_count, 0) / NULLIF(a.node_connect_success_count, 0), 2) as pseudo_success_rate')
            ->where('a.node_connect_success_count', '>=', $minConnected)
            ->orderByDesc('pseudo_success_rate')
            ->orderByDesc('post_connect_failed_count');

        $data = $query->paginate($pageSize);

        return $this->ok([
            'data' => CamelizeResource::collection($data->items()),
            'total' => $data->total(),
            'page' => $data->currentPage(),
            'pageSize' => $data->perPage(),
            'minConnected' => $minConnected,
        ]);
    }

    /**
     * 节点流量报表（客户端上报）
     *
     * GET /performance/nodeTraffic
     */
    public function getNodeTraffic(Request $request): JsonResponse
    {
        $request->validate([
            'nodeId'        => 'nullable|integer',
            'dateFrom'      => 'nullable|date',
            'dateTo'        => 'nullable|date',
            'clientCountry' => 'nullable|string|max:2',
            'platform'      => 'nullable|string|max:100',
            'appId'         => 'nullable|string|max:255',
            'appVersion'    => 'nullable|string|max:50',
            'groupBy'       => 'nullable|in:node,date,hour',
            'includeExternal' => 'nullable|boolean',
            'pageSize'      => 'nullable|integer|min:1|max:200',
        ]);

        $groupBy = $request->input('groupBy', 'node');
        $pageSize = (int) $request->input('pageSize', 50);
        $includeExternal = $request->boolean('includeExternal', false);

        $query = DB::table('v2_node_traffic_aggregated as t')
            ->leftJoin('v2_server as s', 's.id', '=', 't.node_id');

        if (!$includeExternal) {
            $query->where('t.node_id', '>', 0);
        }
        if ($request->filled('nodeId')) {
            $query->where('t.node_id', $request->input('nodeId'));
        }
        if ($request->filled('dateFrom')) {
            $query->where('t.date', '>=', $request->input('dateFrom'));
        }
        if ($request->filled('dateTo')) {
            $query->where('t.date', '<=', $request->input('dateTo'));
        }
        if ($request->filled('clientCountry')) {
            $query->where('t.client_country', $request->input('clientCountry'));
        }
        if ($request->filled('platform')) {
            $query->where('t.platform', $request->input('platform'));
        }
        if ($request->filled('appId')) {
            $query->where('t.app_id', $request->input('appId'));
        }
        if ($request->filled('appVersion')) {
            $query->where('t.app_version', $request->input('appVersion'));
        }

        if ($groupBy === 'date') {
            $data = $query
                ->selectRaw('t.date')
                ->selectRaw('SUM(t.total_usage_seconds) as total_usage_seconds')
                ->selectRaw('ROUND(SUM(t.total_usage_mb), 3) as total_usage_mb')
                ->selectRaw('SUM(t.report_count) as report_count')
                ->groupBy('t.date')
                ->orderByDesc('t.date')
                ->paginate($pageSize);
        } elseif ($groupBy === 'hour') {
            $data = $query
                ->selectRaw('t.date, t.hour')
                ->selectRaw('SUM(t.total_usage_seconds) as total_usage_seconds')
                ->selectRaw('ROUND(SUM(t.total_usage_mb), 3) as total_usage_mb')
                ->selectRaw('SUM(t.report_count) as report_count')
                ->groupBy('t.date', 't.hour')
                ->orderByDesc('t.date')
                ->orderByDesc('t.hour')
                ->paginate($pageSize);
        } else {
            $data = $query
                ->selectRaw('t.node_id, MAX(t.node_ip) as node_ip')
                ->selectRaw('COALESCE(MAX(s.name), MAX(t.node_ip), CONCAT("Server ", t.node_id)) as node_name')
                ->selectRaw('SUM(t.total_usage_seconds) as total_usage_seconds')
                ->selectRaw('ROUND(SUM(t.total_usage_mb), 3) as total_usage_mb')
                ->selectRaw('SUM(t.report_count) as report_count')
                ->groupBy('t.node_id')
                ->orderByDesc('total_usage_mb')
                ->paginate($pageSize);
        }

        return $this->ok([
            'data' => CamelizeResource::collection($data->items()),
            'total' => $data->total(),
            'page' => $data->currentPage(),
            'pageSize' => $data->perPage(),
            'groupBy' => $groupBy,
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
            'dateFrom' => 'nullable|date',
            'dateTo'   => 'nullable|date',
            'appId'    => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:100',
        ]);

        $dateFrom = $request->input('dateFrom', now()->subDays(30)->toDateString());
        $dateTo = $request->input('dateTo', now()->subDay()->toDateString());

        // 留存天数
        $retentionDays = [1, 3, 7, 14, 30];

        // 构建基础查询条件
        $baseConditions = function ($query) use ($request) {
            if ($request->filled('appId')) {
                $query->where('app_id', $request->input('appId'));
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
                        if ($request->filled('appId')) {
                            $q->where('a.app_id', $request->input('appId'));
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
            'data'          => CamelizeResource::collection($result),
            'dateFrom'      => $dateFrom,
            'dateTo'        => $dateTo,
            'retentionDays' => $retentionDays,
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
            'dateFrom'    => 'nullable|date',
            'dateTo'      => 'nullable|date',
            'appId'       => 'nullable|string|max:255',
            'platform'    => 'nullable|string|max:100',
            'granularity' => 'nullable|in:day,week,month',
        ]);

        $dateFrom = $request->input('dateFrom', now()->subDays(30)->toDateString());
        $dateTo = $request->input('dateTo', now()->toDateString());
        $granularity = $request->input('granularity', 'day');

        $baseQuery = DB::table('v3_user_report_count')
            ->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo);

        if ($request->filled('appId')) {
            $baseQuery->where('app_id', $request->input('appId'));
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

        $cacheKey = sprintf(
            'perf:active_users:new_users:%s:%s:%s:%s:%s',
            $granularity,
            $dateFrom,
            $dateTo,
            $request->input('appId', ''),
            $request->input('platform', '')
        );

        $newMap = Cache::remember($cacheKey, 300, function () use ($request, $dateFrom, $dateTo, $granularity) {
            $firstReportSub = DB::table('v3_user_report_count')
                ->selectRaw('user_id, MIN(date) as first_date');

            if ($request->filled('appId')) {
                $firstReportSub->where('app_id', $request->input('appId'));
            }
            if ($request->filled('platform')) {
                $firstReportSub->where('platform', $request->input('platform'));
            }

            $firstReportSub->groupBy('user_id');

            $newQuery = DB::table(DB::raw("({$firstReportSub->toSql()}) as t"))
                ->mergeBindings($firstReportSub)
                ->whereBetween('first_date', [$dateFrom, $dateTo]);

            if ($granularity === 'week') {
                $rows = $newQuery
                    ->selectRaw('YEARWEEK(first_date, 1) as period, COUNT(*) as new_users')
                    ->groupByRaw('YEARWEEK(first_date, 1)')
                    ->orderBy('period')
                    ->get();
            } elseif ($granularity === 'month') {
                $rows = $newQuery
                    ->selectRaw('DATE_FORMAT(first_date, "%Y-%m") as period, COUNT(*) as new_users')
                    ->groupByRaw('DATE_FORMAT(first_date, "%Y-%m")')
                    ->orderBy('period')
                    ->get();
            } else {
                $rows = $newQuery
                    ->selectRaw('first_date as period, COUNT(*) as new_users')
                    ->groupBy('first_date')
                    ->orderBy('first_date')
                    ->get();
            }

            return $rows->mapWithKeys(fn($row) => [(string) $row->period => (int) $row->new_users])->toArray();
        });

        // 获取注册用户数据（来自 UserService，基于 users 表 created_at）
        $regMap = app(UserService::class)->getNewUsersByDateRange(
            $dateFrom,
            $dateTo,
            $granularity,
            [
                'appId' => $request->input('appId'),
                'platform' => $request->input('platform'),
            ]
        );

        $data = $data->map(function ($row) use ($newMap, $regMap) {
            $key = (string) $row->period;
            $row->new_users = $newMap[$key] ?? 0;
            $row->reg_users = $regMap[$key] ?? 0;
            return $row;
        });

        return $this->ok([
            'data'        => CamelizeResource::collection($data),
            'dateFrom'    => $dateFrom,
            'dateTo'      => $dateTo,
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
            'appId'    => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:100',
        ]);

        $today = now()->toDateString();

        $baseQuery = DB::table('v3_user_report_count');

        if ($request->filled('appId')) {
            $baseQuery->where('app_id', $request->input('appId'));
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
                'count'    => $wau,
                'lastWeek' => $wauLastWeek,
                'change'   => $wauLastWeek > 0 ? round(($wau - $wauLastWeek) / $wauLastWeek * 100, 2) : 0,
            ],
            'mau' => [
                'count'     => $mau,
                'lastMonth' => $mauLastMonth,
                'change'    => $mauLastMonth > 0 ? round(($mau - $mauLastMonth) / $mauLastMonth * 100, 2) : 0,
            ],
        ]);
    }

    /**
     * 最近 24 小时用户新增与活跃（按小时）
     *
     * GET /performance/userHourlyStats
     */
    public function getUserHourlyStats(Request $request): JsonResponse
    {
        $request->validate([
            'appId'    => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:100',
            'appVersion' => 'nullable|string|max:50',
            'clientCountry' => 'nullable|string|max:2',
        ]);

        $now = now()->startOfHour();
        $start = (clone $now)->subHours(23);

        $dtExpr = "STR_TO_DATE(CONCAT(date, ' ', LPAD(hour, 2, '0'), ':00:00'), '%Y-%m-%d %H:%i:%s')";

        $applyFilters = function ($query) use ($request) {
            if ($request->filled('appId')) {
                $query->where('app_id', $request->input('appId'));
            }
            if ($request->filled('platform')) {
                $query->where('platform', $request->input('platform'));
            }
            if ($request->filled('appVersion')) {
                $query->where('app_version', $request->input('appVersion'));
            }
            if ($request->filled('clientCountry')) {
                $query->where('client_country', $request->input('clientCountry'));
            }
            return $query;
        };

        // 活跃用户（按小时去重）
        $activeRows = DB::table('v3_user_report_count')
            ->selectRaw("date, hour, COUNT(DISTINCT user_id) as active_users")
            ->whereRaw("{$dtExpr} >= ? AND {$dtExpr} <= ?", [
                $start->format('Y-m-d H:i:s'),
                $now->format('Y-m-d H:i:s'),
            ])
            ->tap($applyFilters)
            ->groupBy('date', 'hour')
            ->get();

        // 新增用户：以该用户首次上报时间所在小时计为新增
        $firstReportSub = DB::table('v3_user_report_count')
            ->selectRaw("user_id, MIN({$dtExpr}) as first_dt")
            ->tap($applyFilters)
            ->groupBy('user_id');

        $newRows = DB::table(DB::raw("({$firstReportSub->toSql()}) as t"))
            ->mergeBindings($firstReportSub)
            ->selectRaw("DATE(first_dt) as date, HOUR(first_dt) as hour, COUNT(*) as new_users")
            ->whereBetween('first_dt', [$start->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')])
            ->groupBy('date', 'hour')
            ->get();

        $activeMap = $activeRows->mapWithKeys(function ($row) {
            $key = $row->date . '_' . str_pad((string) $row->hour, 2, '0', STR_PAD_LEFT);
            return [$key => (int) $row->active_users];
        });

        $newMap = $newRows->mapWithKeys(function ($row) {
            $key = $row->date . '_' . str_pad((string) $row->hour, 2, '0', STR_PAD_LEFT);
            return [$key => (int) $row->new_users];
        });

        $items = [];
        $cursor = (clone $start);
        while ($cursor <= $now) {
            $date = $cursor->toDateString();
            $hour = (int) $cursor->format('H');
            $key = $date . '_' . str_pad((string) $hour, 2, '0', STR_PAD_LEFT);

            $items[] = [
                'time' => $cursor->format('Y-m-d H:00'),
                'new_users' => $newMap[$key] ?? 0,
                'active_users' => $activeMap[$key] ?? 0,
            ];

            $cursor->addHour();
        }

        return $this->ok([
            'data' => CamelizeResource::collection($items),
            'start' => $start->format('Y-m-d H:00'),
            'end' => $now->format('Y-m-d H:00'),
        ]);
    }

    /**
     * 新增 + 活跃用户趋势（按天 / 按月）
     *
     * GET /performance/userGrowth
     */
    public function getUserGrowth(Request $request): JsonResponse
    {
        $request->validate([
            'dateFrom'    => 'nullable|date',
            'dateTo'      => 'nullable|date',
            'granularity' => 'nullable|in:day,month',
            'appId'       => 'nullable|string|max:255',
            'appVersion'  => 'nullable|string|max:50',
            'platform'    => 'nullable|string|max:100',
        ]);

        $dateFrom = $request->input('dateFrom', now()->subDays(30)->toDateString());
        $dateTo = $request->input('dateTo', now()->toDateString());
        $granularity = $request->input('granularity', 'day');

        $baseQuery = DB::table('v3_user_report_count')
            ->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo);

        if ($request->filled('appId')) {
            $baseQuery->where('app_id', $request->input('appId'));
        }
        if ($request->filled('platform')) {
            $baseQuery->where('platform', $request->input('platform'));
        }

        if ($granularity === 'month') {
            $activeRows = (clone $baseQuery)
                ->selectRaw('DATE_FORMAT(date, "%Y-%m") as period, COUNT(DISTINCT user_id) as active_users')
                ->groupByRaw('DATE_FORMAT(date, "%Y-%m")')
                ->orderBy('period')
                ->get();
        } else {
            $activeRows = (clone $baseQuery)
                ->selectRaw('date as period, COUNT(DISTINCT user_id) as active_users')
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        }

        $activeMap = $activeRows->mapWithKeys(fn($row) => [$row->period => (int) $row->active_users]);

        $newMap = app(UserService::class)->getNewUsersByDateRange(
            $dateFrom,
            $dateTo,
            $granularity,
            [
                'appId' => $request->input('appId'),
                'appVersion' => $request->input('appVersion'),
                'platform' => $request->input('platform'),
            ]
        );

        $items = [];
        if ($granularity === 'month') {
            $cursor = \Carbon\Carbon::parse($dateFrom)->startOfMonth();
            $end = \Carbon\Carbon::parse($dateTo)->startOfMonth();
            while ($cursor <= $end) {
                $period = $cursor->format('Y-m');
                $items[] = [
                    'period' => $period,
                    'new_users' => $newMap[$period] ?? 0,
                    'active_users' => $activeMap[$period] ?? 0,
                ];
                $cursor->addMonth();
            }
        } else {
            $cursor = \Carbon\Carbon::parse($dateFrom)->startOfDay();
            $end = \Carbon\Carbon::parse($dateTo)->startOfDay();
            while ($cursor <= $end) {
                $period = $cursor->toDateString();
                $items[] = [
                    'period' => $period,
                    'new_users' => $newMap[$period] ?? 0,
                    'active_users' => $activeMap[$period] ?? 0,
                ];
                $cursor->addDay();
            }
        }

        return $this->ok([
            'data' => CamelizeResource::collection($items),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'granularity' => $granularity,
        ]);
    }
}
