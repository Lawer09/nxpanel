<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NodeReportQueryRequest;
use App\Http\Requests\Admin\NodeServerReportNodeQueryRequest;
use App\Http\Requests\Admin\NodeServerRealtimeRequest;
use App\Http\Requests\Admin\NodeServerReportUserQueryRequest;
use App\Http\Requests\Admin\UserReportHourlyQueryRequest;
use App\Http\Requests\Admin\UserReportNodeFailQueryRequest;
use App\Http\Requests\Admin\UserReportNodeSummaryQueryRequest;
use App\Http\Requests\Admin\UserReportSummaryQueryRequest;
use App\Http\Requests\Admin\UserReportTrafficQueryRequest;
use App\Http\Requests\Admin\ProjectAggregateDailyQueryRequest;
use App\Http\Resources\CamelizeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * 节点实时上报数据（缓存）
     *
     * POST /report/nodeServer/realtime
     */
    public function nodeServerRealtime(NodeServerRealtimeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 50);

        $cacheKey = 'realtime:node_server_report:latest';
        $list = Cache::get($cacheKey, []);
        if (!is_array($list)) {
            $list = [];
        }

        $total = count($list);
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($list, $offset, $pageSize);

        return $this->ok([
            'data' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }

    /**
     * 用户上报汇总查询
     *
     * POST /report/userReport/summary/query
     */
    public function queryUserReportSummary(UserReportSummaryQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $query = DB::table('v3_user_report_summary');
        $this->applyTimeRange($query, $dateFrom, $dateTo, $hourFrom, $hourTo);

        $this->applyWhereIn($query, 'user_id', $filters['userIds'] ?? null);
        $this->applyWhereIn($query, 'app_id', $filters['appIds'] ?? null);
        $this->applyWhereIn($query, 'app_version', $filters['appVersions'] ?? null);
        $this->applyWhereIn($query, 'country', $filters['countries'] ?? null);

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'user_id', 'app_id', 'app_version', 'country']);
            if (empty($selects)) {
                $selects = ['date', 'hour'];
            }
            $query->selectRaw(implode(', ', $selects) . ', SUM(report_count) as report_count');
            $query->groupBy($selects);

            $sortable = array_values(array_unique(array_merge($selects, ['report_count'])));
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('report_count');
            }
        } else {
            $sortable = ['date', 'hour', 'user_id', 'app_id', 'app_version', 'country', 'report_count', 'id', 'created_at', 'updated_at'];
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('date')->orderByDesc('hour')->orderByDesc('id');
            }
        }

        $page = $query->paginate($pageSize);
        $result = [
            'data' => $page->items(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
        ];

        return $this->ok([
            'data' => CamelizeResource::collection($result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pageSize' => $result['pageSize'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ]);
    }

    /**
     * 用户上报节点汇总查询
     *
     * POST /report/userReport/nodeSummary/query
     */
    public function queryUserReportNodeSummary(UserReportNodeSummaryQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $query = DB::table('v3_user_report_node');
        $this->applyTimeRange($query, $dateFrom, $dateTo, $hourFrom, $hourTo);

        $this->applyWhereIn($query, 'node_id', $filters['nodeIds'] ?? null);
        $this->applyWhereIn($query, 'node_host', $filters['nodeHosts'] ?? null);
        $this->applyWhereIn($query, 'probe_stage', $filters['probeStages'] ?? null);
        $this->applyWhereIn($query, 'node_type', $filters['nodeTypes'] ?? null);
        $this->applyWhereIn($query, 'app_id', $filters['appIds'] ?? null);
        $this->applyWhereIn($query, 'app_version', $filters['appVersions'] ?? null);

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'node_id', 'node_host', 'node_type', 'probe_stage', 'app_id', 'app_version']);
            if (empty($selects)) {
                $selects = ['date', 'hour'];
            }
            $query->selectRaw(
                implode(', ', $selects)
                . ', ROUND(SUM(avg_delay * compute_count) / NULLIF(SUM(compute_count), 0), 2) as avg_delay'
                . ', SUM(traffic_usage) as traffic_usage'
                . ', SUM(traffic_use_time) as traffic_use_time'
                . ', SUM(compute_count) as compute_count'
                . ', SUM(success_count) as success_count'
                . ', SUM(fail_count) as fail_count'
                . ', ROUND(100 * SUM(success_count) / NULLIF(SUM(success_count) + SUM(fail_count), 0), 2) as success_rate'
            );
            $query->groupBy($selects);

            $sortable = array_values(array_unique(array_merge($selects, [
                'avg_delay',
                'traffic_usage',
                'traffic_use_time',
                'compute_count',
                'success_count',
                'fail_count',
                'success_rate',
            ])));
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('compute_count');
            }
        } else {
            $query->selectRaw(
                '*, ROUND(100 * success_count / NULLIF(success_count + fail_count, 0), 2) as success_rate'
            );

            $sortable = [
                'date', 'hour', 'node_id', 'node_host', 'node_type', 'probe_stage',
                'app_id', 'app_version',
                'avg_delay', 'traffic_usage', 'traffic_use_time', 'compute_count',
                'success_count', 'fail_count', 'success_rate', 'id', 'created_at', 'updated_at',
            ];
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('date')->orderByDesc('hour')->orderByDesc('compute_count');
            }
        }

        $page = $query->paginate($pageSize);
        $result = [
            'data' => $page->items(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
        ];

        return $this->ok([
            'data' => CamelizeResource::collection($result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pageSize' => $result['pageSize'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ]);
    }

    /**
     * 用户上报流量查询
     *
     * POST /report/userReport/traffic/query
     */
    public function queryUserReportTraffic(UserReportTrafficQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $query = DB::table('v3_user_report_user');
        $this->applyTimeRange($query, $dateFrom, $dateTo, $hourFrom, $hourTo);

        $this->applyWhereIn($query, 'user_id', $filters['userIds'] ?? null);
        $this->applyWhereIn($query, 'app_id', $filters['appIds'] ?? null);
        $this->applyWhereIn($query, 'app_version', $filters['appVersions'] ?? null);
        $this->applyWhereIn($query, 'country', $filters['countries'] ?? null);

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'user_id', 'app_id', 'app_version', 'country']);
            if (empty($selects)) {
                $selects = ['date', 'hour'];
            }
            $query->selectRaw(
                implode(', ', $selects)
                . ', SUM(traffic_usage) as traffic_usage'
                . ', SUM(traffic_use_time) as traffic_use_time'
                . ', SUM(compute_count) as compute_count'
            );
            $query->groupBy($selects);

            $sortable = array_values(array_unique(array_merge($selects, ['traffic_usage', 'traffic_use_time', 'compute_count'])));
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('traffic_usage');
            }
        } else {
            $sortable = ['date', 'hour', 'user_id', 'app_id', 'app_version', 'country', 'traffic_usage', 'traffic_use_time', 'compute_count', 'id', 'created_at', 'updated_at'];
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('date')->orderByDesc('hour')->orderByDesc('traffic_usage');
            }
        }

        $page = $query->paginate($pageSize);
        $result = [
            'data' => $page->items(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
        ];

        return $this->ok([
            'data' => CamelizeResource::collection($result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pageSize' => $result['pageSize'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ]);
    }

    /**
     * 用户上报节点失败明细查询
     *
     * POST /report/userReport/nodeFail/query
     */
    public function queryUserReportNodeFail(UserReportNodeFailQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $query = DB::table('v3_user_report_node_fail');
        $this->applyTimeRange($query, $dateFrom, $dateTo, $hourFrom, $hourTo);

        $this->applyWhereIn($query, 'node_id', $filters['nodeIds'] ?? null);
        $this->applyWhereIn($query, 'node_host', $filters['nodeHosts'] ?? null);
        $this->applyWhereIn($query, 'probe_stage', $filters['probeStages'] ?? null);
        $this->applyWhereIn($query, 'error_code', $filters['errorCodes'] ?? null);

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'node_id', 'node_host', 'node_type', 'probe_stage', 'error_code']);
            if (empty($selects)) {
                $selects = ['date', 'hour'];
            }
            $query->selectRaw(implode(', ', $selects) . ', COUNT(*) as fail_count, MAX(report_at_ms) as last_report_at_ms');
            $query->groupBy($selects);

            $sortable = array_values(array_unique(array_merge($selects, ['fail_count', 'last_report_at_ms'])));
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('fail_count')->orderByDesc('last_report_at_ms');
            }
        } else {
            $sortable = ['date', 'hour', 'node_id', 'node_host', 'node_type', 'probe_stage', 'error_code', 'report_at_ms', 'id', 'created_at'];
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('report_at_ms')->orderByDesc('id');
            }
        }

        $page = $query->paginate($pageSize);
        $result = [
            'data' => $page->items(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
        ];

        return $this->ok([
            'data' => CamelizeResource::collection($result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pageSize' => $result['pageSize'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ]);
    }

    /**
     * 节点上报报表（节点维度）查询
     *
     * POST /report/nodeServerReport/node/query
     */
    public function queryNodeServerReportNode(NodeServerReportNodeQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $query = DB::table('v3_node_server_report_node');
        $this->applyTimeRange($query, $dateFrom, $dateTo, $hourFrom, $hourTo);

        $this->applyWhereIn($query, 'node_id', $filters['nodeIds'] ?? null);
        $this->applyWhereIn($query, 'node_type', $filters['nodeTypes'] ?? null);
        $this->applyWhereIn($query, 'node_host', $filters['nodeHosts'] ?? null);
        $this->applyWhereIn($query, 'node_public_ip', $filters['nodePublicIps'] ?? null);
        $this->applyWhereIn($query, 'app_id', $filters['appIds'] ?? null);
        $this->applyWhereIn($query, 'app_version', $filters['appVersions'] ?? null);

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'node_id', 'node_type', 'node_host', 'node_public_ip', 'app_id', 'app_version']);
            if (empty($selects)) {
                $selects = ['date', 'hour'];
            }

            $query->selectRaw(
                implode(', ', $selects)
                . ', SUM(traffic_upload) as traffic_upload'
                . ', SUM(traffic_download) as traffic_download'
                . ', ROUND(SUM(avg_cpu_usage * compute_count) / NULLIF(SUM(compute_count), 0), 6) as avg_cpu_usage'
                . ', ROUND(SUM(avg_mem_usage * compute_count) / NULLIF(SUM(compute_count), 0), 6) as avg_mem_usage'
                . ', MAX(max_cpu_usage) as max_cpu_usage'
                . ', MAX(max_mem_usage) as max_mem_usage'
                . ', ROUND(SUM(avg_disk_usage * compute_count) / NULLIF(SUM(compute_count), 0), 6) as avg_disk_usage'
                . ', ROUND(SUM(avg_inbound_speed * compute_count) / NULLIF(SUM(compute_count), 0), 6) as avg_inbound_speed'
                . ', ROUND(SUM(avg_outbound_speed * compute_count) / NULLIF(SUM(compute_count), 0), 6) as avg_outbound_speed'
                . ', MAX(max_inbound_speed) as max_inbound_speed'
                . ', MAX(max_outbound_speed) as max_outbound_speed'
                . ', ROUND(SUM(avg_tcp_connections * compute_count) / NULLIF(SUM(compute_count), 0), 6) as avg_tcp_connections'
                . ', MAX(max_tcp_connections) as max_tcp_connections'
                . ', ROUND(SUM(avg_alive_users * compute_count) / NULLIF(SUM(compute_count), 0), 6) as avg_alive_users'
                . ', MAX(max_alive_users) as max_alive_users'
                . ', SUM(compute_count) as compute_count'
            );
            $query->groupBy($selects);

            $sortable = array_values(array_unique(array_merge($selects, [
                'traffic_upload', 'traffic_download',
                'avg_cpu_usage', 'avg_mem_usage', 'max_cpu_usage', 'max_mem_usage',
                'avg_disk_usage', 'avg_inbound_speed', 'avg_outbound_speed',
                'max_inbound_speed', 'max_outbound_speed',
                'avg_tcp_connections', 'max_tcp_connections',
                'avg_alive_users', 'max_alive_users', 'compute_count',
            ])));
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('compute_count');
            }
        } else {
            $sortable = [
                'date', 'hour', 'node_id', 'node_type', 'node_host', 'node_public_ip',
                'app_id', 'app_version',
                'traffic_upload', 'traffic_download', 'avg_cpu_usage', 'avg_mem_usage',
                'max_cpu_usage', 'max_mem_usage', 'avg_disk_usage',
                'avg_inbound_speed', 'avg_outbound_speed', 'max_inbound_speed', 'max_outbound_speed',
                'avg_tcp_connections', 'max_tcp_connections', 'avg_alive_users', 'max_alive_users',
                'compute_count', 'id', 'created_at', 'updated_at',
            ];
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('date')->orderByDesc('hour')->orderByDesc('compute_count');
            }
        }

        $page = $query->paginate($pageSize);
        $result = [
            'data' => $page->items(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
        ];

        return $this->ok([
            'data' => CamelizeResource::collection($result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pageSize' => $result['pageSize'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ]);
    }

    /**
     * 节点上报报表（用户维度）查询
     *
     * POST /report/nodeServerReport/user/query
     */
    public function queryNodeServerReportUser(NodeServerReportUserQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $query = DB::table('v3_node_server_report_user');
        $this->applyTimeRange($query, $dateFrom, $dateTo, $hourFrom, $hourTo);

        $this->applyWhereIn($query, 'node_id', $filters['nodeIds'] ?? null);
        $this->applyWhereIn($query, 'user_id', $filters['userIds'] ?? null);
        $this->applyWhereIn($query, 'app_id', $filters['appIds'] ?? null);
        $this->applyWhereIn($query, 'app_version', $filters['appVersions'] ?? null);
        $this->applyWhereIn($query, 'country', $filters['countries'] ?? null);

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'node_id', 'user_id', 'app_id', 'app_version', 'country']);
            if (empty($selects)) {
                $selects = ['date', 'hour'];
            }
            $query->selectRaw(
                implode(', ', $selects)
                . ', SUM(traffic_upload) as traffic_upload'
                . ', SUM(traffic_download) as traffic_download'
                . ', SUM(compute_count) as compute_count'
            );
            $query->groupBy($selects);

            $sortable = array_values(array_unique(array_merge($selects, ['traffic_upload', 'traffic_download', 'compute_count'])));
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('traffic_download');
            }
        } else {
            $sortable = [
                'date', 'hour', 'node_id', 'user_id', 'app_id', 'app_version', 'country',
                'traffic_upload', 'traffic_download', 'compute_count', 'id', 'created_at', 'updated_at',
            ];
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('date')->orderByDesc('hour')->orderByDesc('traffic_download');
            }
        }

        $page = $query->paginate($pageSize);
        $result = [
            'data' => $page->items(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
        ];

        return $this->ok([
            'data' => CamelizeResource::collection($result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pageSize' => $result['pageSize'],
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ]);
    }

    /**
     * 统一节点小时报表查询
     *
     * POST /report/nodeReport/query
     */
    public function queryNodeReport(NodeReportQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $query = DB::table('v3_report_node_hourly');
        $this->applyTimeRange($query, $dateFrom, $dateTo, $hourFrom, $hourTo);

        $this->applyWhereIn($query, 'node_id', $filters['nodeIds'] ?? null);
        $this->applyWhereIn($query, 'node_type', $filters['nodeTypes'] ?? null);
        $this->applyWhereIn($query, 'node_host', $filters['nodeHosts'] ?? null);
        $this->applyWhereIn($query, 'node_public_ip', $filters['nodePublicIps'] ?? null);
        $this->applyWhereIn($query, 'probe_stage', $filters['probeStages'] ?? null);
        $this->applyWhereIn($query, 'app_id', $filters['appIds'] ?? null);
        $this->applyWhereIn($query, 'app_version', $filters['appVersions'] ?? null);

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'node_id', 'node_type', 'node_host', 'node_public_ip', 'probe_stage', 'app_id', 'app_version']);
            if (empty($selects)) {
                $selects = ['date', 'hour'];
            }

            $query->selectRaw(
                implode(', ', $selects)
                . ', SUM(traffic_upload) as traffic_upload'
                . ', SUM(traffic_download) as traffic_download'
                . ', ROUND(SUM(avg_cpu_usage * report_count_node) / NULLIF(SUM(report_count_node), 0), 6) as avg_cpu_usage'
                . ', ROUND(SUM(avg_mem_usage * report_count_node) / NULLIF(SUM(report_count_node), 0), 6) as avg_mem_usage'
                . ', MAX(max_cpu_usage) as max_cpu_usage'
                . ', MAX(max_mem_usage) as max_mem_usage'
                . ', ROUND(SUM(avg_disk_usage * report_count_node) / NULLIF(SUM(report_count_node), 0), 6) as avg_disk_usage'
                . ', ROUND(SUM(avg_inbound_speed * report_count_node) / NULLIF(SUM(report_count_node), 0), 6) as avg_inbound_speed'
                . ', ROUND(SUM(avg_outbound_speed * report_count_node) / NULLIF(SUM(report_count_node), 0), 6) as avg_outbound_speed'
                . ', MAX(max_inbound_speed) as max_inbound_speed'
                . ', MAX(max_outbound_speed) as max_outbound_speed'
                . ', ROUND(SUM(avg_tcp_connections * report_count_node) / NULLIF(SUM(report_count_node), 0), 6) as avg_tcp_connections'
                . ', MAX(max_tcp_connections) as max_tcp_connections'
                . ', ROUND(SUM(avg_alive_users * report_count_node) / NULLIF(SUM(report_count_node), 0), 6) as avg_alive_users'
                . ', MAX(max_alive_users) as max_alive_users'
                . ', ROUND(SUM(avg_delay * report_count_user) / NULLIF(SUM(report_count_user), 0), 2) as avg_delay'
                . ', SUM(traffic_usage) as traffic_usage'
                . ', SUM(traffic_use_time) as traffic_use_time'
                . ', SUM(success_count) as success_count'
                . ', SUM(fail_count) as fail_count'
                . ', ROUND(100 * SUM(success_count) / NULLIF(SUM(success_count) + SUM(fail_count), 0), 2) as success_rate'
                . ', SUM(report_count_node) as report_count_node'
                . ', SUM(report_count_user) as report_count_user'
            );
            $query->groupBy($selects);

            $sortable = array_values(array_unique(array_merge($selects, [
                'traffic_upload', 'traffic_download',
                'avg_cpu_usage', 'avg_mem_usage', 'max_cpu_usage', 'max_mem_usage',
                'avg_disk_usage', 'avg_inbound_speed', 'avg_outbound_speed',
                'max_inbound_speed', 'max_outbound_speed',
                'avg_tcp_connections', 'max_tcp_connections',
                'avg_alive_users', 'max_alive_users',
                'avg_delay', 'traffic_usage', 'traffic_use_time',
                'success_count', 'fail_count', 'success_rate',
                'report_count_node', 'report_count_user',
            ])));
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('report_count_node');
            }
        } else {
            $sortable = [
                'date', 'hour', 'node_id', 'node_type', 'node_host', 'node_public_ip', 'probe_stage',
                'app_id', 'app_version',
                'traffic_upload', 'traffic_download',
                'avg_cpu_usage', 'avg_mem_usage', 'max_cpu_usage', 'max_mem_usage',
                'avg_disk_usage', 'avg_inbound_speed', 'avg_outbound_speed',
                'max_inbound_speed', 'max_outbound_speed',
                'avg_tcp_connections', 'max_tcp_connections',
                'avg_alive_users', 'max_alive_users',
                'avg_delay', 'traffic_usage', 'traffic_use_time',
                'success_count', 'fail_count', 'success_rate',
                'report_count_node', 'report_count_user',
                'id', 'created_at', 'updated_at',
            ];
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('date')->orderByDesc('hour')->orderByDesc('report_count_node');
            }
        }

        $page = $query->paginate($pageSize);

        return $this->ok([
            'data' => CamelizeResource::collection($page->items()),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ]);
    }

    /**
     * 统一用户小时报表查询
     *
     * POST /report/userReport/query
     */
    public function queryUserReportHourly(UserReportHourlyQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $query = DB::table('v3_report_user_hourly');
        $this->applyTimeRange($query, $dateFrom, $dateTo, $hourFrom, $hourTo);

        $this->applyWhereIn($query, 'user_id', $filters['userIds'] ?? null);
        $this->applyWhereIn($query, 'app_id', $filters['appIds'] ?? null);
        $this->applyWhereIn($query, 'app_version', $filters['appVersions'] ?? null);
        $this->applyWhereIn($query, 'country', $filters['countries'] ?? null);

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'user_id', 'app_id', 'app_version', 'country']);
            if (empty($selects)) {
                $selects = ['date', 'hour'];
            }
            $query->selectRaw(
                implode(', ', $selects)
                . ', SUM(traffic_usage) as traffic_usage'
                . ', SUM(traffic_use_time) as traffic_use_time'
                . ', SUM(traffic_upload) as traffic_upload'
                . ', SUM(traffic_download) as traffic_download'
                . ', SUM(report_count_user) as report_count_user'
                . ', SUM(report_count_node) as report_count_node'
            );
            $query->groupBy($selects);

            $sortable = array_values(array_unique(array_merge($selects, ['traffic_usage', 'traffic_use_time', 'traffic_upload', 'traffic_download', 'report_count_user', 'report_count_node'])));
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('traffic_download');
            }
        } else {
            $sortable = [
                'date', 'hour', 'user_id', 'app_id', 'app_version', 'country',
                'traffic_usage', 'traffic_use_time', 'traffic_upload', 'traffic_download',
                'report_count_user', 'report_count_node',
                'id', 'created_at', 'updated_at',
            ];
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('date')->orderByDesc('hour')->orderByDesc('traffic_download');
            }
        }

        $page = $query->paginate($pageSize);

        return $this->ok([
            'data' => CamelizeResource::collection($page->items()),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ]);
    }

    public function queryProjectReport(ProjectAggregateDailyQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = strtolower((string) ($validated['orderDirection'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $dimensionMap = [
            'reportDate' => 'report_date',
            'projectCode' => 'project_code',
            'country' => 'country',
        ];

        $metricMap = [
            'newUsers' => 'new_users',
            'reportNewUsers' => 'report_new_users',
            'dauUsers' => 'dau_users',
            'adRevenue' => 'ad_revenue',
            'adRequests' => 'ad_requests',
            'adMatchedRequests' => 'ad_matched_requests',
            'adImpressions' => 'ad_impressions',
            'adClicks' => 'ad_clicks',
            'adEcpm' => 'ad_ecpm',
            'adCtr' => 'ad_ctr',
            'adMatchRate' => 'ad_match_rate',
            'adShowRate' => 'ad_show_rate',
            'adSpendCost' => 'ad_spend_cost',
            'adSpendCpi' => 'ad_spend_cpi',
            'adSpendCpc' => 'ad_spend_cpc',
            'adSpendCpm' => 'ad_spend_cpm',
            'trafficUsageMb' => 'traffic_usage_mb',
            'trafficCost' => 'traffic_cost',
            'profit' => 'profit',
            'roi' => 'roi',
            'id' => 'id',
            'updatedAt' => 'updated_at',
        ];

        $query = DB::table('project_daily_aggregates')
            ->where('report_date', '>=', $dateFrom)
            ->where('report_date', '<=', $dateTo);

        $projectCodes = is_array($filters['projectCodes'] ?? null) ? $filters['projectCodes'] : [];
        if (!empty($projectCodes)) {
            $query->whereIn('project_code', $projectCodes);
        }

        $countries = is_array($filters['countries'] ?? null) ? $filters['countries'] : [];
        if (!empty($countries)) {
            $query->whereIn('country', array_map(static fn ($country) => strtoupper((string) $country), $countries));
        }

        if (empty($groupBy)) {
            $sortable = array_merge(array_keys($metricMap), ['totalCost']);
            $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'reportDate';
            $orderColumn = $orderKey === 'reportDate' ? 'report_date' : $metricMap[$orderKey];

            $total = (clone $query)->count();
            $rows = $query
                ->orderBy($orderColumn, $orderDirection)
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
        } else {
            $groupDimensions = array_values(array_unique(array_filter($groupBy, static fn ($item) => is_string($item) && isset($dimensionMap[$item]))));
            if (empty($groupDimensions)) {
                $groupDimensions = ['reportDate'];
            }

            $groupColumns = array_map(static fn ($key) => $dimensionMap[$key], $groupDimensions);
            $groupQuery = clone $query;

            foreach ($groupColumns as $groupColumn) {
                $groupQuery->selectRaw($groupColumn);
                $groupQuery->groupBy($groupColumn);
            }

            $groupQuery->selectRaw('SUM(new_users) as new_users')
                ->selectRaw('SUM(report_new_users) as report_new_users')
                ->selectRaw('SUM(dau_users) as dau_users')
                ->selectRaw('SUM(ad_revenue) as ad_revenue')
                ->selectRaw('SUM(ad_requests) as ad_requests')
                ->selectRaw('SUM(ad_matched_requests) as ad_matched_requests')
                ->selectRaw('SUM(ad_impressions) as ad_impressions')
                ->selectRaw('SUM(ad_clicks) as ad_clicks')
                ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
                ->selectRaw('SUM(traffic_usage_mb) as traffic_usage_mb')
                ->selectRaw('SUM(traffic_cost) as traffic_cost')
                ->selectRaw('SUM(profit) as profit')
                ->selectRaw('MAX(updated_at) as updated_at')
                ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_revenue)/SUM(ad_impressions)*1000,6) END as ad_ecpm')
                ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_clicks)/SUM(ad_impressions)*100,6) END as ad_ctr')
                ->selectRaw('CASE WHEN SUM(ad_requests)=0 THEN NULL ELSE ROUND(SUM(ad_matched_requests)/SUM(ad_requests)*100,6) END as ad_match_rate')
                ->selectRaw('CASE WHEN SUM(ad_matched_requests)=0 THEN NULL ELSE ROUND(SUM(ad_impressions)/SUM(ad_matched_requests)*100,6) END as ad_show_rate')
                ->selectRaw('CASE WHEN SUM(new_users)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)/SUM(new_users),6) END as ad_spend_cpi')
                ->selectRaw('CASE WHEN SUM(ad_clicks)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)/SUM(ad_clicks),6) END as ad_spend_cpc')
                ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)*1000/SUM(ad_impressions),6) END as ad_spend_cpm')
                ->selectRaw('CASE WHEN (SUM(ad_spend_cost)+SUM(traffic_cost))=0 THEN NULL ELSE ROUND(SUM(ad_revenue)/(SUM(ad_spend_cost)+SUM(traffic_cost)),6) END as roi');

            $sortable = array_values(array_unique(array_merge($groupDimensions, [
                'newUsers', 'reportNewUsers', 'dauUsers', 'adRevenue', 'adRequests', 'adMatchedRequests',
                'adImpressions', 'adClicks', 'adEcpm', 'adCtr', 'adMatchRate', 'adShowRate',
                'adSpendCost', 'adSpendCpi', 'adSpendCpc', 'adSpendCpm', 'trafficUsageMb',
                'trafficCost', 'totalCost', 'profit', 'roi', 'updatedAt',
            ])));

            $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'adRevenue';
            $orderColumn = $dimensionMap[$orderKey] ?? $metricMap[$orderKey] ?? 'ad_revenue';

            $countQuery = DB::table(DB::raw("({$groupQuery->toSql()}) as t"))
                ->mergeBindings($groupQuery)
                ->selectRaw('COUNT(*) as cnt')
                ->first();
            $total = (int) ($countQuery->cnt ?? 0);

            $rows = $groupQuery
                ->orderBy($orderColumn, $orderDirection)
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
        }

        $data = $rows->map(function ($row) {
            return [
                'id' => isset($row->id) ? (int) $row->id : null,
                'reportDate' => isset($row->report_date) ? (string) $row->report_date : null,
                'projectCode' => $row->project_code ?? null,
                'country' => $row->country ?? null,
                'newUsers' => (int) ($row->new_users ?? 0),
                'reportNewUsers' => (int) ($row->report_new_users ?? 0),
                'dauUsers' => (int) ($row->dau_users ?? 0),
                'adRevenue' => $this->formatDecimal($row->ad_revenue ?? null),
                'adRequests' => (int) ($row->ad_requests ?? 0),
                'adMatchedRequests' => (int) ($row->ad_matched_requests ?? 0),
                'adImpressions' => (int) ($row->ad_impressions ?? 0),
                'adClicks' => (int) ($row->ad_clicks ?? 0),
                'adEcpm' => $this->formatDecimal($row->ad_ecpm ?? null),
                'adCtr' => $this->formatDecimal($row->ad_ctr ?? null),
                'adMatchRate' => $this->formatDecimal($row->ad_match_rate ?? null),
                'adShowRate' => $this->formatDecimal($row->ad_show_rate ?? null),
                'impressionsPerUser' => $this->ratio((float) ($row->ad_impressions ?? 0), (float) ($row->dau_users ?? 0)),
                'arpu' => $this->ratio((float) ($row->ad_revenue ?? 0), (float) ($row->dau_users ?? 0)),
                'adSpendCost' => $this->formatDecimal($row->ad_spend_cost ?? null),
                'adSpendCpi' => $this->formatDecimal($row->ad_spend_cpi ?? null),
                'adSpendCpc' => $this->formatDecimal($row->ad_spend_cpc ?? null),
                'adSpendCpm' => $this->formatDecimal($row->ad_spend_cpm ?? null),
                'trafficUsageMb' => $this->formatDecimal($row->traffic_usage_mb ?? null),
                'trafficCost' => $this->formatDecimal($row->traffic_cost ?? null),
                'totalCost' => $this->formatDecimal(($row->ad_spend_cost ?? 0) + ($row->traffic_cost ?? 0)),
                'profit' => $this->formatDecimal($row->profit ?? null),
                'roi' => $this->formatDecimal($row->roi ?? null),
                'updatedAt' => $row->updated_at ?? null,
            ];
        });

        return $this->ok([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'groupBy' => $groupBy,
        ]);
    }

    private function normalizeGroupBy(array $groupBy, array $allowed): array
    {
        $allowedMap = array_flip($allowed);
        $normalized = [];

        foreach ($groupBy as $field) {
            if (!is_string($field)) {
                continue;
            }
            if (!isset($allowedMap[$field])) {
                continue;
            }
            $normalized[$field] = $field;
        }

        return array_values($normalized);
    }

    private function applyTimeRange($query, string $dateFrom, string $dateTo, $hourFrom = null, $hourTo = null): void
    {
        $query->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo);

        if ($hourFrom !== null) {
            $query->where('hour', '>=', (int) $hourFrom);
        }
        if ($hourTo !== null) {
            $query->where('hour', '<=', (int) $hourTo);
        }
    }

    private function applyWhereIn($query, string $field, $values): void
    {
        if (!is_array($values)) {
            return;
        }

        $values = array_values(array_filter($values, function ($value) {
            return $value !== null && $value !== '';
        }));

        if (empty($values)) {
            return;
        }

        $query->whereIn($field, $values);
    }

    private function normalizeOrderDirection($value): string
    {
        return is_string($value) && strtolower($value) === 'asc' ? 'asc' : 'desc';
    }

    private function ratio(float $a, float $b): ?string
    {
        if ($b == 0.0) {
            return null;
        }

        return $this->formatDecimal($a / $b);
    }

    private function formatDecimal($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 6, '.', '');
    }

}
