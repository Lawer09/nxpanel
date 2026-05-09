<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NodeReportQueryRequest;
use App\Http\Requests\Admin\NodeMainReportQueryRequest;
use App\Http\Requests\Admin\NodeServerReportNodeQueryRequest;
use App\Http\Requests\Admin\NodeServerRealtimeRequest;
use App\Http\Requests\Admin\NodeServerReportUserQueryRequest;
use App\Http\Requests\Admin\NodeSubReportQueryRequest;
use App\Http\Requests\Admin\UserReportHourlyQueryRequest;
use App\Http\Requests\Admin\UserReportNodeFailQueryRequest;
use App\Http\Requests\Admin\UserReportNodeSummaryQueryRequest;
use App\Http\Requests\Admin\UserReportSummaryQueryRequest;
use App\Http\Requests\Admin\UserReportTrafficQueryRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\NodeMainReportService;
use App\Services\NodeSubReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(
        private readonly NodeMainReportService $nodeMainReportService,
        private readonly NodeSubReportService $nodeSubReportService
    )
    {
    }

    /**
     * 节点主报表查询
     *
     * POST /report/node/query
     */
    public function queryNode(NodeMainReportQueryRequest $request): JsonResponse
    {
        $result = $this->nodeMainReportService->query($request->validated());

        return $this->ok([
            'data' => CamelizeResource::collection($result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pageSize' => $result['pageSize'],
            'groupBy' => $result['groupBy'],
            'metric_availability' => $result['metric_availability'],
            'bandwidth_source' => $result['bandwidth_source'],
            'dateFrom' => $result['dateFrom'],
            'dateTo' => $result['dateTo'],
        ]);
    }

    /**
     * 子表校对查询
     *
     * POST /report/node/subtable/query
     */
    public function queryNodeSubTable(NodeSubReportQueryRequest $request): JsonResponse
    {
        $result = $this->nodeSubReportService->query($request->validated());

        return $this->ok([
            'data' => CamelizeResource::collection($result['data']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pageSize' => $result['pageSize'],
            'subTable' => $result['subTable'],
            'groupBy' => $result['groupBy'],
            'metricMap' => $result['metricMap'],
            'date' => $result['date'],
            'hour' => $result['hour'],
            'minute' => $result['minute'],
        ]);
    }

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

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'node_id', 'node_host', 'node_type', 'probe_stage']);
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

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'node_id', 'node_type', 'node_host', 'node_public_ip']);
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

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'node_id', 'node_type', 'node_host', 'node_public_ip', 'probe_stage']);
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

}
