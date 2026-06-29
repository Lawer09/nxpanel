<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class FirebaseAnalyticsService
{
    /**
     * 查询最近接收事件（Redis List）。
     */
    public function recentEvents(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $page = max(1, $page);
        $pageSize = min(max(1, $pageSize), 200);

        $key = 'firebase-event-recv:recent:events';
        $total = (int) Redis::llen($key);

        if ($total <= 0) {
            return [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => 0,
                'items' => [],
            ];
        }

        $start = ($page - 1) * $pageSize;
        if ($start >= $total) {
            return [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'items' => [],
            ];
        }

        $end = $start + $pageSize - 1;
        $rawItems = Redis::lrange($key, $start, $end);

        $items = [];
        foreach ($rawItems as $rawItem) {
            $decoded = json_decode((string) $rawItem, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'items' => $items,
        ];
    }

    public function dashboardSummary(array $params): array
    {
        return $this->remember('dashboard-summary', $params, function () use ($params) {
            $base = $this->baseCommonQuery($params);

            $totalEvents = (clone $base)->count();
            $activeDevices = (clone $base)->distinct('device_id')->count('device_id');
            $appOpenCount = (clone $base)->where('event_name', 'app_open')->count();
            $vpnSessionCount = (clone $base)->where('event_name', 'vpn_session')->count();
            $apiErrorCount = (clone $base)->where('event_name', 'server_api_error')->count();
            $duplicateEventCount = (clone $base)->sum('duplicate_count');

            $sessionAgg = $this->baseSessionQuery($params)
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(success = 1) as success_count')
                ->first();

            $probeAgg = $this->baseProbeQuery($params)
                ->selectRaw('SUM(success_count) as success_count')
                ->selectRaw('SUM(fail_count) as fail_count')
                ->first();

            $vpnSuccessRate = $this->safeRate((int) ($sessionAgg->success_count ?? 0), (int) ($sessionAgg->total ?? 0));
            $probeSuccessRate = $this->safeRate((int) ($probeAgg->success_count ?? 0), (int) ($probeAgg->success_count ?? 0) + (int) ($probeAgg->fail_count ?? 0));

            $avgReceiveDelayMs = $this->avgReceiveDelayMs($params);

            $compare = empty($params['compare']) ? $this->summaryCompare($params) : [
                'total_events_rate' => 0,
                'active_devices_rate' => 0,
                'app_open_rate' => 0,
                'vpn_success_rate_diff' => 0,
                'probe_success_rate_diff' => 0,
                'api_error_rate' => 0,
            ];

            return [
                'total_events' => $totalEvents,
                'active_devices' => $activeDevices,
                'app_open_count' => $appOpenCount,
                'vpn_session_count' => $vpnSessionCount,
                'vpn_success_rate' => $vpnSuccessRate,
                'probe_success_rate' => $probeSuccessRate,
                'api_error_count' => $apiErrorCount,
                'duplicate_event_count' => (int) $duplicateEventCount,
                'avg_receive_delay_ms' => $avgReceiveDelayMs,
                'compare' => $compare,
            ];
        }, 60, ['compare']);
    }

    public function eventTrend(array $params): array
    {
        return $this->remember('event-trend', $params, function () use ($params) {
            $interval = $this->resolveInterval($params);
            $timeExpr = $this->timeBucketExpression($params, $interval);

            $items = $this->baseCommonQuery($params)
                ->selectRaw("{$timeExpr} as time")
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(event_name = "app_open") as app_open')
                ->selectRaw('SUM(event_name = "vpn_session") as vpn_session')
                ->selectRaw('SUM(event_name = "vpn_probe") as vpn_probe')
                ->selectRaw('SUM(event_name = "server_api_error") as server_api_error')
                ->groupBy('time')
                ->orderBy('time')
                ->get();

            return [
                'interval' => $interval,
                'items' => $items,
            ];
        }, 60);
    }

    public function vpnQualityTrend(array $params): array
    {
        return $this->remember('vpn-quality-trend', $params, function () use ($params) {
            $interval = $this->resolveInterval($params);
            $timeExpr = $this->timeBucketExpression($params, $interval);
            $p95ByBucket = $this->p95ValuesByBucket('firebase_event_vpn_session', 'connect_ms', $params, $interval);

            $items = $this->baseSessionQuery($params)
                ->selectRaw("{$timeExpr} as time")
                ->selectRaw('COUNT(*) as session_count')
                ->selectRaw('SUM(success = 1) as success_count')
                ->selectRaw('SUM(success = 0) as fail_count')
                ->selectRaw('AVG(connect_ms) as avg_connect_ms')
                ->selectRaw('AVG(duration_ms) as avg_duration_ms')
                ->selectRaw('SUM(retry_count) as retry_count')
                ->groupBy('time')
                ->orderBy('time')
                ->get();

            $items = $items->map(function ($item) use ($p95ByBucket) {
                $item->p95_connect_ms = $p95ByBucket[(string) $item->time] ?? null;
                $item->success_rate = $this->safeRate((int) $item->success_count, (int) $item->session_count);
                return $item;
            });

            return [
                'interval' => $interval,
                'items' => $items,
            ];
        }, 60);
    }

    public function regionQuality(array $params): array
    {
        return $this->remember('region-quality', $params, function () use ($params) {
            $sortMap = [
                'event_count' => 'event_count',
                'vpn_success_rate' => 'vpn_success_rate',
                'api_error_count' => 'api_error_count',
                'avg_connect_ms' => 'avg_connect_ms',
            ];
            $sortBy = $sortMap[$params['sort_by'] ?? 'event_count'] ?? 'event_count';
            $order = ($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $limit = (int) ($params['limit'] ?? 50);

            $sessionAgg = $this->baseSessionQuery($params)
                ->selectRaw("COALESCE(c.user_country, '') as join_user_country")
                ->selectRaw("COALESCE(c.user_region, '') as join_user_region")
                ->selectRaw('COUNT(*) as session_total')
                ->selectRaw('SUM(success = 1) as session_success')
                ->selectRaw('AVG(connect_ms) as avg_connect_ms')
                ->groupByRaw("COALESCE(c.user_country, ''), COALESCE(c.user_region, '')");

            $items = $this->baseCommonQuery($params)
                ->leftJoinSub($sessionAgg, 'session_region', function ($join) {
                    $join->on(DB::raw("COALESCE(c.user_country, '')"), '=', 'session_region.join_user_country')
                        ->on(DB::raw("COALESCE(c.user_region, '')"), '=', 'session_region.join_user_region');
                })
                ->selectRaw('c.user_country, c.user_region')
                ->selectRaw('COUNT(*) as event_count')
                ->selectRaw('COUNT(DISTINCT c.device_id) as active_devices')
                ->selectRaw('SUM(c.event_name = "vpn_session") as vpn_session_count')
                ->selectRaw('SUM(c.event_name = "server_api_error") as api_error_count')
                ->selectRaw('COALESCE(ROUND(MAX(session_region.session_success) / NULLIF(MAX(session_region.session_total), 0), 4), 0) as vpn_success_rate')
                ->selectRaw('COALESCE(ROUND(MAX(session_region.avg_connect_ms), 0), 0) as avg_connect_ms')
                ->groupBy('c.user_country', 'c.user_region')
                ->orderBy($sortBy, $order)
                ->limit($limit)
                ->get();

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function errorsTop(array $params): array
    {
        return $this->remember('errors-top', $params, function () use ($params) {
            $errorType = $params['error_type'];
            $limit = (int) ($params['limit'] ?? 10);

            if ($errorType === 'vpn_session') {
                $items = $this->baseSessionQuery($params)
                    ->whereNotNull('error_code')
                    ->selectRaw('error_stage, error_code, COUNT(*) as count')
                    ->selectRaw('COUNT(DISTINCT c.device_id) as affected_devices')
                    ->groupBy('error_stage', 'error_code')
                    ->orderByDesc('count')
                    ->limit($limit)
                    ->get();

                $total = $items->sum('count');
                $items = $items->map(fn ($item, $index) => (object) [
                    'rank' => $index + 1,
                    'error_stage' => $item->error_stage,
                    'error_code' => $item->error_code,
                    'count' => (int) $item->count,
                    'ratio' => $this->safeRate((int) $item->count, (int) $total),
                    'affected_devices' => (int) $item->affected_devices,
                ]);
            } elseif ($errorType === 'vpn_probe') {
                $items = $this->baseProbeResultQuery($params)
                    ->whereNotNull('error_code')
                    ->selectRaw('error_code, COUNT(*) as count')
                    ->selectRaw('COUNT(DISTINCT node_id) as affected_nodes')
                    ->selectRaw('COUNT(DISTINCT c.device_id) as affected_devices')
                    ->groupBy('error_code')
                    ->orderByDesc('count')
                    ->limit($limit)
                    ->get();

                $total = $items->sum('count');
                $items = $items->map(fn ($item, $index) => (object) [
                    'rank' => $index + 1,
                    'error_code' => $item->error_code,
                    'count' => (int) $item->count,
                    'ratio' => $this->safeRate((int) $item->count, (int) $total),
                    'affected_nodes' => (int) $item->affected_nodes,
                    'affected_devices' => (int) $item->affected_devices,
                ]);
            } else {
                $items = $this->baseApiErrorQuery($params)
                    ->selectRaw('api_domain, api_path, http_status, error_stage, error_code, COUNT(*) as count')
                    ->selectRaw('COUNT(DISTINCT c.device_id) as affected_devices')
                    ->groupBy('api_domain', 'api_path', 'http_status', 'error_stage', 'error_code')
                    ->orderByDesc('count')
                    ->limit($limit)
                    ->get();

                $total = $items->sum('count');
                $items = $items->map(fn ($item, $index) => (object) [
                    'rank' => $index + 1,
                    'api_domain' => $item->api_domain,
                    'api_path' => $item->api_path,
                    'http_status' => (int) $item->http_status,
                    'error_stage' => $item->error_stage,
                    'error_code' => $item->error_code,
                    'count' => (int) $item->count,
                    'ratio' => $this->safeRate((int) $item->count, (int) $total),
                    'affected_devices' => (int) $item->affected_devices,
                ]);
            }

            return [
                'error_type' => $errorType,
                'items' => $items,
            ];
        }, 60);
    }

    public function nodesQualityRank(array $params): array
    {
        return $this->remember('nodes-quality-rank', $params, function () use ($params) {
            $source = $params['source'] ?? 'session';
            $querySource = $source === 'probe' ? 'probe' : 'session';
            $sortBy = $params['sort_by'] ?? 'session_count';
            $order = ($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $limit = (int) ($params['limit'] ?? 20);
            $sortsAfterP95 = $sortBy === 'p95_connect_ms';

            if ($querySource === 'probe') {
                $sortMap = [
                    'session_count' => 'test_count',
                    'success_rate' => 'success_rate',
                    'avg_connect_ms' => 'avg_tcp_connect_ms',
                    'total_bytes' => 'test_count',
                ];
                $query = $this->baseProbeResultQuery($params)
                    ->selectRaw('node_id, node_name, node_country, protocol')
                    ->selectRaw('COUNT(*) as test_count')
                    ->selectRaw('SUM(success = 1) as success_count')
                    ->selectRaw('ROUND(SUM(success = 1) / NULLIF(COUNT(*), 0), 4) as success_rate')
                    ->selectRaw('AVG(latency_ms) as avg_latency_ms')
                    ->selectRaw('AVG(tcp_connect_ms) as avg_tcp_connect_ms')
                    ->selectRaw('AVG(tls_hk_ms) as avg_tls_hk_ms')
                    ->selectRaw('AVG(proxy_hk_ms) as avg_proxy_hk_ms')
                    ->groupBy('node_id', 'node_name', 'node_country', 'protocol');

                if (!$sortsAfterP95) {
                    $query->orderBy($sortMap[$sortBy] ?? 'test_count', $order)->limit($limit);
                }

                $rows = $query->get();
                $nodeIds = $rows->pluck('node_id')->filter()->unique()->values()->all();
                $p95ByNodeId = $this->p95ValuesByField('firebase_event_vpn_probe_result', 'latency_ms', 'node_id', $nodeIds, $params);
                $topErrors = $this->topProbeErrorCodesByNodeIds($params, $nodeIds);

                $items = $rows->map(function ($item) use ($p95ByNodeId, $topErrors) {
                    return (object) [
                        'node_id' => $item->node_id,
                        'node_name' => $item->node_name,
                        'node_country' => $item->node_country,
                        'node_region' => null,
                        'protocol' => $item->protocol,
                        'session_count' => (int) $item->test_count,
                        'success_count' => (int) $item->success_count,
                        'success_rate' => $this->safeRate((int) $item->success_count, (int) $item->test_count),
                        'avg_connect_ms' => (int) $item->avg_tcp_connect_ms,
                        'p95_connect_ms' => $p95ByNodeId[(string) $item->node_id] ?? null,
                        'avg_duration_ms' => (int) $item->avg_latency_ms,
                        'total_bytes' => 0,
                        'top_error_code' => $topErrors[(string) $item->node_id] ?? null,
                    ];
                });
            } else {
                $sortMap = [
                    'session_count' => 'session_count',
                    'success_rate' => 'success_rate',
                    'avg_connect_ms' => 'avg_connect_ms',
                    'total_bytes' => 'total_bytes',
                ];
                $query = $this->baseSessionQuery($params)
                    ->selectRaw('node_id, node_name, node_country, node_region, protocol')
                    ->selectRaw('COUNT(*) as session_count')
                    ->selectRaw('SUM(success = 1) as success_count')
                    ->selectRaw('ROUND(SUM(success = 1) / NULLIF(COUNT(*), 0), 4) as success_rate')
                    ->selectRaw('AVG(connect_ms) as avg_connect_ms')
                    ->selectRaw('AVG(duration_ms) as avg_duration_ms')
                    ->selectRaw('SUM(total_bytes) as total_bytes')
                    ->groupBy('node_id', 'node_name', 'node_country', 'node_region', 'protocol');

                if (!$sortsAfterP95) {
                    $query->orderBy($sortMap[$sortBy] ?? 'session_count', $order)->limit($limit);
                }

                $rows = $query->get();
                $nodeIds = $rows->pluck('node_id')->filter()->unique()->values()->all();
                $protocols = $rows->pluck('protocol')->filter()->unique()->values()->all();
                $p95ByNodeId = $this->p95ValuesByField('firebase_event_vpn_session', 'connect_ms', 'node_id', $nodeIds, $params);
                $topErrors = $this->topErrorCodesByProtocols($params, $protocols);

                $items = $rows->map(function ($item) use ($p95ByNodeId, $topErrors) {
                    return (object) [
                        'node_id' => $item->node_id,
                        'node_name' => $item->node_name,
                        'node_country' => $item->node_country,
                        'node_region' => $item->node_region,
                        'protocol' => $item->protocol,
                        'session_count' => (int) $item->session_count,
                        'success_count' => (int) $item->success_count,
                        'success_rate' => $this->safeRate((int) $item->success_count, (int) $item->session_count),
                        'avg_connect_ms' => (int) $item->avg_connect_ms,
                        'p95_connect_ms' => $p95ByNodeId[(string) $item->node_id] ?? null,
                        'avg_duration_ms' => (int) $item->avg_duration_ms,
                        'total_bytes' => (int) $item->total_bytes,
                        'top_error_code' => $topErrors[(string) $item->protocol] ?? null,
                    ];
                });
            }

            if ($sortsAfterP95) {
                $items = $this->sortCollectionByField($items, 'p95_connect_ms', $order)->take($limit)->values();
            }

            $items = $items->values()->map(function ($item, $index) {
                $item->rank = $index + 1;
                return $item;
            });

            return [
                'source' => $source,
                'items' => $items,
            ];
        }, 60);
    }

    /**
     * Query merged node status metrics from VPN session and probe result samples.
     */
    public function nodesStatus(array $params): array
    {
        return $this->remember('nodes-status', $params, function () use ($params) {
            $page = max(1, (int) ($params['page'] ?? 1));
            $pageSize = min(max(1, (int) ($params['page_size'] ?? 20)), 200);
            $sortBy = $params['sort_by'] ?? 'diagnosis_priority';
            $order = $params['order'] ?? ($sortBy === 'diagnosis_priority' ? 'asc' : 'desc');
            $order = $order === 'asc' ? 'asc' : 'desc';

            $sessionQuery = $this->baseSessionQuery($params);
            $this->applyNodeFilters($sessionQuery, $params, 's');
            $sessionRows = $sessionQuery
                ->selectRaw('s.node_id, s.node_name, s.node_country, s.node_region, s.protocol')
                ->selectRaw('COUNT(*) as session_count')
                ->selectRaw('SUM(s.success = 1) as session_success_count')
                ->selectRaw('SUM(s.success = 0) as session_fail_count')
                ->selectRaw('AVG(s.connect_ms) as avg_connect_ms')
                ->selectRaw('AVG(s.duration_ms) as avg_duration_ms')
                ->selectRaw('SUM(COALESCE(s.retry_count, 0) > 0) as retry_session_count')
                ->selectRaw('SUM(s.total_bytes) as total_bytes')
                ->selectRaw('MAX(c.received_at) as last_session_received_at')
                ->groupBy('s.node_id', 's.node_name', 's.node_country', 's.node_region', 's.protocol')
                ->get();

            $probeQuery = $this->baseProbeResultQuery($params);
            $this->applyNodeFilters($probeQuery, $params, 'r');
            $probeRows = $probeQuery
                ->selectRaw('r.node_id, r.node_name, r.node_country, r.node_region, r.protocol')
                ->selectRaw('COUNT(*) as probe_test_count')
                ->selectRaw('SUM(r.success = 1) as probe_success_count')
                ->selectRaw('SUM(r.success = 0) as probe_fail_count')
                ->selectRaw('AVG(r.latency_ms) as avg_latency_ms')
                ->selectRaw('AVG(r.tcp_connect_ms) as avg_tcp_connect_ms')
                ->selectRaw('AVG(r.tls_hk_ms) as avg_tls_hk_ms')
                ->selectRaw('AVG(r.proxy_hk_ms) as avg_proxy_hk_ms')
                ->selectRaw('MAX(c.received_at) as last_probe_received_at')
                ->groupBy('r.node_id', 'r.node_name', 'r.node_country', 'r.node_region', 'r.protocol')
                ->get();

            $p95ConnectByNodeKey = $this->p95ValuesByNodeKey('session', 'connect_ms', $params);
            $p95LatencyByNodeKey = $this->p95ValuesByNodeKey('probe', 'latency_ms', $params);
            $sessionTopErrors = $this->topErrorCodesByNodeKey('session', $params);
            $probeTopErrors = $this->topErrorCodesByNodeKey('probe', $params);
            $itemsByKey = [];

            foreach ($sessionRows as $row) {
                $key = $this->nodeKeyFromRow($row);
                $item = $this->emptyNodeStatusItem($row);
                $sessionCount = (int) $row->session_count;
                $sessionSuccessCount = (int) $row->session_success_count;

                $item->session_count = $sessionCount;
                $item->session_success_count = $sessionSuccessCount;
                $item->session_fail_count = (int) $row->session_fail_count;
                $item->session_success_rate = $this->safeRate($sessionSuccessCount, $sessionCount);
                $item->avg_connect_ms = (int) ($row->avg_connect_ms ?? 0);
                $item->p95_connect_ms = $p95ConnectByNodeKey[$key] ?? null;
                $item->avg_duration_ms = (int) ($row->avg_duration_ms ?? 0);
                $item->retry_session_count = (int) ($row->retry_session_count ?? 0);
                $item->total_bytes = (int) ($row->total_bytes ?? 0);
                $item->session_top_error_code = $sessionTopErrors[$key] ?? null;
                $item->last_session_received_at = $row->last_session_received_at;
                $itemsByKey[$key] = $item;
            }

            foreach ($probeRows as $row) {
                $key = $this->nodeKeyFromRow($row);
                $item = $itemsByKey[$key] ?? $this->emptyNodeStatusItem($row);
                $probeTestCount = (int) $row->probe_test_count;
                $probeSuccessCount = (int) $row->probe_success_count;

                $item->probe_test_count = $probeTestCount;
                $item->probe_success_count = $probeSuccessCount;
                $item->probe_fail_count = (int) $row->probe_fail_count;
                $item->probe_success_rate = $this->safeRate($probeSuccessCount, $probeTestCount);
                $item->avg_latency_ms = (int) ($row->avg_latency_ms ?? 0);
                $item->p95_latency_ms = $p95LatencyByNodeKey[$key] ?? null;
                $item->avg_tcp_connect_ms = (int) ($row->avg_tcp_connect_ms ?? 0);
                $item->avg_tls_hk_ms = (int) ($row->avg_tls_hk_ms ?? 0);
                $item->avg_proxy_hk_ms = (int) ($row->avg_proxy_hk_ms ?? 0);
                $item->probe_top_error_code = $probeTopErrors[$key] ?? null;
                $item->last_probe_received_at = $row->last_probe_received_at;
                $itemsByKey[$key] = $item;
            }

            $items = collect(array_values($itemsByKey))->map(function ($item) {
                $hasSession = $item->session_count > 0;
                $hasProbe = $item->probe_test_count > 0;
                $item->sample_scope = $hasSession && $hasProbe
                    ? 'dual'
                    : ($hasProbe ? 'probe_only' : 'session_only');
                $item->rate_gap = $hasSession && $hasProbe
                    ? round(abs($item->session_success_rate - $item->probe_success_rate), 4)
                    : null;

                [$item->diagnosis_status, $item->diagnosis_priority] = $this->diagnoseNodeStatus($item);
                return $item;
            });

            if (!empty($params['sample_scope']) && $params['sample_scope'] !== 'all') {
                $items = $items->where('sample_scope', $params['sample_scope'])->values();
            }

            if (!empty($params['diagnosis_status'])) {
                $items = $items->where('diagnosis_status', $params['diagnosis_status'])->values();
            }

            $items = $this->sortCollectionByField($items, $sortBy, $order)->values();
            $total = $items->count();
            $items = $items->forPage($page, $pageSize)->values();

            return [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'items' => $items,
            ];
        }, 60);
    }

    /**
     * Query connection summary metrics for a single node filter set.
     */
    public function nodeConnectionSummary(array $params): array
    {
        return $this->remember('node-connection-summary', $params, function () use ($params) {
            $query = $this->baseSessionQuery($params);
            $this->applyNodeFilters($query, $params, 's');

            $agg = $query
                ->selectRaw('COUNT(*) as session_count')
                ->selectRaw('SUM(s.success = 1) as success_count')
                ->selectRaw('SUM(s.success = 0) as fail_count')
                ->selectRaw('COUNT(DISTINCT c.device_id) as active_devices')
                ->selectRaw('AVG(s.connect_ms) as avg_connect_ms')
                ->selectRaw('AVG(s.duration_ms) as avg_duration_ms')
                ->selectRaw('SUM(COALESCE(s.retry_count, 0) > 0) as retry_session_count')
                ->selectRaw('SUM(s.upload_bytes) as total_upload_bytes')
                ->selectRaw('SUM(s.download_bytes) as total_download_bytes')
                ->selectRaw('SUM(s.total_bytes) as total_bytes')
                ->selectRaw('MAX(c.received_at) as last_received_at')
                ->first();

            $sessionCount = (int) ($agg->session_count ?? 0);
            $successCount = (int) ($agg->success_count ?? 0);
            $retrySessionCount = (int) ($agg->retry_session_count ?? 0);

            return [
                'session_count' => $sessionCount,
                'success_count' => $successCount,
                'fail_count' => (int) ($agg->fail_count ?? 0),
                'success_rate' => $this->safeRate($successCount, $sessionCount),
                'active_devices' => (int) ($agg->active_devices ?? 0),
                'avg_connect_ms' => (int) ($agg->avg_connect_ms ?? 0),
                'p95_connect_ms' => $this->p95SessionConnectMs($params),
                'avg_duration_ms' => (int) ($agg->avg_duration_ms ?? 0),
                'retry_session_count' => $retrySessionCount,
                'retry_rate' => $this->safeRate($retrySessionCount, $sessionCount),
                'total_upload_bytes' => (int) ($agg->total_upload_bytes ?? 0),
                'total_download_bytes' => (int) ($agg->total_download_bytes ?? 0),
                'total_bytes' => (int) ($agg->total_bytes ?? 0),
                'top_error_code' => $this->topSessionErrorCode($params),
                'last_received_at' => $agg->last_received_at ?? null,
            ];
        }, 60);
    }

    /**
     * Query connection error code distribution for a single node filter set.
     */
    public function nodeConnectionErrorDistribution(array $params): array
    {
        return $this->remember('node-connection-error-distribution', $params, function () use ($params) {
            $limit = min(max(1, (int) ($params['limit'] ?? 20)), 200);
            $base = $this->baseSessionQuery($params);
            $this->applyNodeFilters($base, $params, 's');
            $base->whereNotNull('s.error_code');
            $total = (clone $base)->count();

            $items = $base
                ->selectRaw('s.error_stage, s.error_code, COUNT(*) as count')
                ->selectRaw('COUNT(DISTINCT c.device_id) as affected_devices')
                ->groupBy('s.error_stage', 's.error_code')
                ->orderByDesc('count')
                ->limit($limit)
                ->get()
                ->map(fn ($item) => (object) [
                    'error_stage' => $item->error_stage,
                    'error_code' => $item->error_code,
                    'count' => (int) $item->count,
                    'ratio' => $this->safeRate((int) $item->count, (int) $total),
                    'affected_devices' => (int) $item->affected_devices,
                ]);

            return [
                'items' => $items,
            ];
        }, 60);
    }

    /**
     * Query paginated connection detail rows for a single node filter set.
     */
    public function nodeConnectionResults(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = min(max(1, (int) ($params['page_size'] ?? 20)), 200);
        $sortMap = [
            'received_at' => 'c.received_at',
            'event_time_ms' => 'c.event_time_ms',
            'connect_ms' => 's.connect_ms',
            'duration_ms' => 's.duration_ms',
            'retry_count' => 's.retry_count',
            'id' => 's.event_id',
        ];
        $sortBy = $params['sort_by'] ?? 'received_at';
        $order = ($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query = $this->baseSessionQuery($params);
        $this->applyNodeFilters($query, $params, 's');

        if (array_key_exists('success', $params)) {
            $query->where('s.success', (bool) $params['success']);
        }
        if (!empty($params['error_stage'])) {
            $query->where('s.error_stage', $params['error_stage']);
        }
        if (!empty($params['error_code'])) {
            $query->where('s.error_code', $params['error_code']);
        }

        $total = (clone $query)->count();
        $items = $query
            ->orderBy($sortMap[$sortBy] ?? $sortMap['received_at'], $order)
            ->orderBy('s.event_id', $order)
            ->forPage($page, $pageSize)
            ->get([
                's.event_id as id',
                's.event_id',
                'c.received_at',
                'c.event_time_ms',
                'c.app_id',
                'c.platform',
                'c.app_version',
                'c.device_id',
                'c.user_id',
                'c.user_country',
                'c.user_region',
                'c.network_type',
                'c.isp',
                'c.asn',
                's.session_id',
                's.node_id',
                's.node_name',
                's.node_country',
                's.node_region',
                's.protocol',
                's.connect_type',
                's.success',
                's.connect_ms',
                's.duration_ms',
                's.retry_count',
                's.error_stage',
                's.error_code',
                's.error_message',
            ]);

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'items' => $items,
        ];
    }

    public function appOpenSummary(array $params): array
    {
        return $this->remember('app-open-summary', $params, function () use ($params) {
            $query = $this->baseCommonQuery($params)->where('event_name', 'app_open');

            $openCount = (clone $query)->count();
            $activeDevices = (clone $query)->distinct('device_id')->count('device_id');

            $appOpen = $this->baseAppOpenQuery($params)
                ->selectRaw('AVG(launch_ms) as avg_launch_ms')
                ->selectRaw('COUNT(*) as total')
                ->first();

            $p95 = $this->p95Value('firebase_event_app_open', 'launch_ms', $params);

            $coldStart = $this->baseAppOpenQuery($params)->where('open_type', 'cold_start')->count();
            $coldStartRatio = $this->safeRate($coldStart, $openCount);

            $topInstallChannel = $this->baseAppOpenQuery($params)
                ->selectRaw('install_channel, COUNT(*) as count')
                ->groupBy('install_channel')
                ->orderByDesc('count')
                ->value('install_channel');

            return [
                'open_count' => $openCount,
                'active_devices' => $activeDevices,
                'avg_launch_ms' => (int) ($appOpen->avg_launch_ms ?? 0),
                'p95_launch_ms' => $p95,
                'cold_start_ratio' => $coldStartRatio,
                'top_install_channel' => $topInstallChannel,
            ];
        }, 60);
    }

    public function appOpenTrend(array $params): array
    {
        return $this->remember('app-open-trend', $params, function () use ($params) {
            $interval = $this->resolveInterval($params);
            $timeExpr = $this->timeBucketExpression($params, $interval);
            $p95ByBucket = $this->p95ValuesByBucket('firebase_event_app_open', 'launch_ms', $params, $interval);

            $items = $this->baseAppOpenQuery($params)
                ->selectRaw("{$timeExpr} as time")
                ->selectRaw('COUNT(*) as open_count')
                ->selectRaw('COUNT(DISTINCT c.device_id) as active_devices')
                ->selectRaw('AVG(launch_ms) as avg_launch_ms')
                ->groupBy('time')
                ->orderBy('time')
                ->get();

            $items = $items->map(function ($item) use ($p95ByBucket) {
                $item->p95_launch_ms = $p95ByBucket[(string) $item->time] ?? null;
                return $item;
            });

            return [
                'interval' => $interval,
                'items' => $items,
            ];
        }, 60);
    }

    public function appOpenTypeDistribution(array $params): array
    {
        return $this->remember('app-open-type-distribution', $params, function () use ($params) {
            $items = $this->baseAppOpenQuery($params)
                ->selectRaw('open_type, COUNT(*) as count')
                ->groupBy('open_type')
                ->orderByDesc('count')
                ->get();

            $total = $items->sum('count');
            $items = $items->map(fn ($item) => (object) [
                'open_type' => $item->open_type,
                'count' => (int) $item->count,
                'ratio' => $this->safeRate((int) $item->count, (int) $total),
            ]);

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function appOpenVersionRank(array $params): array
    {
        return $this->remember('app-open-version-rank', $params, function () use ($params) {
            $limit = (int) ($params['limit'] ?? 20);

            $items = $this->baseCommonQuery($params)
                ->where('event_name', 'app_open')
                ->selectRaw('app_version')
                ->selectRaw('COUNT(*) as open_count')
                ->selectRaw('COUNT(DISTINCT device_id) as active_devices')
                ->groupBy('app_version')
                ->orderByDesc('open_count')
                ->limit($limit)
                ->get();

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function vpnSummary(array $params): array
    {
        return $this->remember('vpn-summary', $params, function () use ($params) {
            $agg = $this->baseSessionQuery($params)
                ->selectRaw('COUNT(*) as session_count')
                ->selectRaw('SUM(success = 1) as success_count')
                ->selectRaw('SUM(success = 0) as fail_count')
                ->selectRaw('AVG(connect_ms) as avg_connect_ms')
                ->selectRaw('AVG(duration_ms) as avg_duration_ms')
                ->selectRaw('SUM(upload_bytes) as total_upload_bytes')
                ->selectRaw('SUM(download_bytes) as total_download_bytes')
                ->selectRaw('SUM(total_bytes) as total_bytes')
                ->selectRaw('SUM(retry_count) as retry_session_count')
                ->first();

            $sessionCount = (int) ($agg->session_count ?? 0);
            $successCount = (int) ($agg->success_count ?? 0);

            return [
                'session_count' => $sessionCount,
                'success_count' => $successCount,
                'fail_count' => (int) ($agg->fail_count ?? 0),
                'success_rate' => $this->safeRate($successCount, $sessionCount),
                'avg_connect_ms' => (int) ($agg->avg_connect_ms ?? 0),
                'p95_connect_ms' => $this->p95Value('firebase_event_vpn_session', 'connect_ms', $params),
                'avg_duration_ms' => (int) ($agg->avg_duration_ms ?? 0),
                'total_upload_bytes' => (int) ($agg->total_upload_bytes ?? 0),
                'total_download_bytes' => (int) ($agg->total_download_bytes ?? 0),
                'total_bytes' => (int) ($agg->total_bytes ?? 0),
                'retry_session_count' => (int) ($agg->retry_session_count ?? 0),
                'retry_rate' => $this->safeRate((int) ($agg->retry_session_count ?? 0), $sessionCount),
            ];
        }, 60);
    }

    public function vpnFailStageDistribution(array $params): array
    {
        return $this->remember('vpn-fail-stage-distribution', $params, function () use ($params) {
            $items = $this->baseSessionQuery($params)
                ->selectRaw('fail_stage, COUNT(*) as count')
                ->groupBy('fail_stage')
                ->orderByDesc('count')
                ->get();

            $total = $items->sum('count');
            $items = $items->map(fn ($item) => (object) [
                'fail_stage' => $item->fail_stage,
                'count' => (int) $item->count,
                'ratio' => $this->safeRate((int) $item->count, (int) $total),
            ]);

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function vpnErrorStageDistribution(array $params): array
    {
        return $this->remember('vpn-error-stage-distribution', $params, function () use ($params) {
            $items = $this->baseSessionQuery($params)
                ->selectRaw('error_stage, COUNT(*) as count')
                ->groupBy('error_stage')
                ->orderByDesc('count')
                ->get();

            $total = $items->sum('count');
            $items = $items->map(fn ($item) => (object) [
                'error_stage' => $item->error_stage,
                'count' => (int) $item->count,
                'ratio' => $this->safeRate((int) $item->count, (int) $total),
            ]);

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function vpnConnectTypeAnalysis(array $params): array
    {
        return $this->remember('vpn-connect-type-analysis', $params, function () use ($params) {
            $items = $this->baseSessionQuery($params)
                ->selectRaw('connect_type, COUNT(*) as session_count')
                ->selectRaw('SUM(success = 1) as success_count')
                ->selectRaw('AVG(connect_ms) as avg_connect_ms')
                ->selectRaw('SUM(retry_count) as retry_count')
                ->groupBy('connect_type')
                ->orderByDesc('session_count')
                ->get();

            $items = $items->map(fn ($item) => (object) [
                'connect_type' => $item->connect_type,
                'session_count' => (int) $item->session_count,
                'success_rate' => $this->safeRate((int) $item->success_count, (int) $item->session_count),
                'avg_connect_ms' => (int) $item->avg_connect_ms,
                'retry_rate' => $this->safeRate((int) $item->retry_count, (int) $item->session_count),
            ]);

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function vpnProtocolQuality(array $params): array
    {
        return $this->remember('vpn-protocol-quality', $params, function () use ($params) {
            $items = $this->baseSessionQuery($params)
                ->selectRaw('protocol, COUNT(*) as session_count')
                ->selectRaw('SUM(success = 1) as success_count')
                ->selectRaw('AVG(connect_ms) as avg_connect_ms')
                ->selectRaw('AVG(duration_ms) as avg_duration_ms')
                ->groupBy('protocol')
                ->orderByDesc('session_count')
                ->get();

            $topErrors = $this->topErrorCodesByProtocols($params, $items->pluck('protocol')->filter()->unique()->values()->all());

            $items = $items->map(fn ($item) => (object) [
                'protocol' => $item->protocol,
                'session_count' => (int) $item->session_count,
                'success_rate' => $this->safeRate((int) $item->success_count, (int) $item->session_count),
                'avg_connect_ms' => (int) $item->avg_connect_ms,
                'avg_duration_ms' => (int) $item->avg_duration_ms,
                'top_error_code' => $topErrors[(string) $item->protocol] ?? null,
            ]);

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function probeSummary(array $params): array
    {
        return $this->remember('probe-summary', $params, function () use ($params) {
            $probeAgg = $this->baseProbeQuery($params)
                ->selectRaw('COUNT(*) as probe_count')
                ->selectRaw('SUM(node_count) as probe_result_count')
                ->selectRaw('SUM(success_count) as success_count')
                ->selectRaw('SUM(fail_count) as fail_count')
                ->selectRaw('AVG(duration_ms) as avg_duration_ms')
                ->first();

            $resultAgg = $this->baseProbeResultQuery($params)
                ->selectRaw('AVG(latency_ms) as avg_latency_ms')
                ->selectRaw('COUNT(*) as total')
                ->first();

            $p95 = $this->p95Value('firebase_event_vpn_probe_result', 'latency_ms', $params);

            return [
                'probe_count' => (int) ($probeAgg->probe_count ?? 0),
                'probe_result_count' => (int) ($probeAgg->probe_result_count ?? 0),
                'avg_probe_success_rate' => $this->safeRate((int) ($probeAgg->success_count ?? 0), (int) (($probeAgg->success_count ?? 0) + ($probeAgg->fail_count ?? 0))),
                'avg_latency_ms' => (int) ($resultAgg->avg_latency_ms ?? 0),
                'p95_latency_ms' => $p95,
                'avg_duration_ms' => (int) ($probeAgg->avg_duration_ms ?? 0),
                'failed_result_count' => (int) ($probeAgg->fail_count ?? 0),
            ];
        }, 60);
    }

    public function probeTrend(array $params): array
    {
        return $this->remember('probe-trend', $params, function () use ($params) {
            $interval = $this->resolveInterval($params);
            $timeExpr = $this->timeBucketExpression($params, $interval);

            $items = $this->baseProbeQuery($params)
                ->selectRaw("{$timeExpr} as time")
                ->selectRaw('COUNT(*) as probe_count')
                ->selectRaw('SUM(node_count) as result_count')
                ->selectRaw('SUM(success_count) as success_count')
                ->selectRaw('SUM(fail_count) as fail_count')
                ->selectRaw('AVG(duration_ms) as avg_duration_ms')
                ->groupBy('time')
                ->orderBy('time')
                ->get();

            $items = $items->map(function ($item) {
                $item->success_rate = $this->safeRate((int) $item->success_count, (int) $item->result_count);
                return $item;
            });

            return [
                'interval' => $interval,
                'items' => $items,
            ];
        }, 60);
    }

    public function probeTriggerDistribution(array $params): array
    {
        return $this->remember('probe-trigger-distribution', $params, function () use ($params) {
            $items = $this->baseProbeQuery($params)
                ->selectRaw('probe_trigger, COUNT(*) as count')
                ->groupBy('probe_trigger')
                ->orderByDesc('count')
                ->get();

            $total = $items->sum('count');
            $items = $items->map(fn ($item) => (object) [
                'probe_trigger' => $item->probe_trigger,
                'count' => (int) $item->count,
                'ratio' => $this->safeRate((int) $item->count, (int) $total),
            ]);

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function probeTypeDistribution(array $params): array
    {
        return $this->remember('probe-type-distribution', $params, function () use ($params) {
            $items = $this->baseProbeQuery($params)
                ->selectRaw('probe_type, COUNT(*) as count')
                ->groupBy('probe_type')
                ->orderByDesc('count')
                ->get();

            $total = $items->sum('count');
            $items = $items->map(fn ($item) => (object) [
                'probe_type' => $item->probe_type,
                'count' => (int) $item->count,
                'ratio' => $this->safeRate((int) $item->count, (int) $total),
            ]);

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function probeNodeRank(array $params): array
    {
        return $this->remember('probe-node-rank', $params, function () use ($params) {
            $sortBy = $params['sort_by'] ?? 'success_rate';
            $order = ($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $limit = (int) ($params['limit'] ?? 20);
            $sortsAfterP95 = $sortBy === 'p95_latency_ms';
            $sortMap = [
                'success_rate' => 'success_rate',
                'avg_latency_ms' => 'avg_latency_ms',
                'avg_tcp_connect_ms' => 'avg_tcp_connect_ms',
                'avg_tls_hk_ms' => 'avg_tls_hk_ms',
                'avg_proxy_hk_ms' => 'avg_proxy_hk_ms',
            ];

            $query = $this->baseProbeResultQuery($params)
                ->selectRaw('node_id, node_name, node_country, protocol')
                ->selectRaw('COUNT(*) as test_count')
                ->selectRaw('SUM(success = 1) as success_count')
                ->selectRaw('ROUND(SUM(success = 1) / NULLIF(COUNT(*), 0), 4) as success_rate')
                ->selectRaw('AVG(latency_ms) as avg_latency_ms')
                ->selectRaw('AVG(tcp_connect_ms) as avg_tcp_connect_ms')
                ->selectRaw('AVG(tls_hk_ms) as avg_tls_hk_ms')
                ->selectRaw('AVG(proxy_hk_ms) as avg_proxy_hk_ms')
                ->groupBy('node_id', 'node_name', 'node_country', 'protocol');

            if (!$sortsAfterP95) {
                $query->orderBy($sortMap[$sortBy] ?? 'success_rate', $order)->limit($limit);
            }

            $rows = $query->get();
            $nodeIds = $rows->pluck('node_id')->filter()->unique()->values()->all();
            $p95ByNodeId = $this->p95ValuesByField('firebase_event_vpn_probe_result', 'latency_ms', 'node_id', $nodeIds, $params);
            $topErrors = $this->topProbeErrorCodesByNodeIds($params, $nodeIds);

            $items = $rows->map(fn ($item) => (object) [
                'node_id' => $item->node_id,
                'node_name' => $item->node_name,
                'node_country' => $item->node_country,
                'protocol' => $item->protocol,
                'test_count' => (int) $item->test_count,
                'success_rate' => $this->safeRate((int) $item->success_count, (int) $item->test_count),
                'avg_latency_ms' => (int) $item->avg_latency_ms,
                'p95_latency_ms' => $p95ByNodeId[(string) $item->node_id] ?? null,
                'avg_tcp_connect_ms' => (int) $item->avg_tcp_connect_ms,
                'avg_tls_hk_ms' => (int) $item->avg_tls_hk_ms,
                'avg_proxy_hk_ms' => (int) $item->avg_proxy_hk_ms,
                'top_error_code' => $topErrors[(string) $item->node_id] ?? null,
            ]);

            if ($sortsAfterP95) {
                $items = $this->sortCollectionByField($items, 'p95_latency_ms', $order)->take($limit)->values();
            }

            $items = $items->values()->map(function ($item, $index) {
                $item->rank = $index + 1;
                return $item;
            });

            return [
                'items' => $items,
            ];
        }, 60);
    }

    /**
     * Query paginated Firebase VPN probe node statistics.
     */
    public function probeNodeStats(array $params): array
    {
        return $this->remember('probe-node-stats', $params, function () use ($params) {
            $page = max(1, (int) ($params['page'] ?? 1));
            $pageSize = min(max(1, (int) ($params['page_size'] ?? 20)), 200);
            $sortBy = $params['sort_by'] ?? 'success_rate';
            $order = ($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $sortsAfterP95 = $sortBy === 'p95_latency_ms';
            $sortMap = [
                'node_id' => 'node_id',
                'test_count' => 'test_count',
                'success_count' => 'success_count',
                'fail_count' => 'fail_count',
                'success_rate' => 'success_rate',
                'avg_latency_ms' => 'avg_latency_ms',
                'avg_tcp_connect_ms' => 'avg_tcp_connect_ms',
                'avg_tls_hk_ms' => 'avg_tls_hk_ms',
                'avg_proxy_hk_ms' => 'avg_proxy_hk_ms',
                'last_received_at' => 'last_received_at',
            ];

            $query = DB::table('firebase_event_vpn_probe_result as r')
                ->join('firebase_event_vpn_probe as p', 'p.event_id', '=', 'r.event_id')
                ->join('firebase_event_common as c', 'c.event_id', '=', 'r.event_id');

            $query = $this->applyCommonFilters($query, $params, 'c');
            $query = $this->applyTimeFilters($query, $params, 'c');
            $this->applyProbeResultFilters($query, $params);

            $query->selectRaw('r.node_id, r.node_name, r.node_country, r.node_region, r.protocol')
                ->selectRaw('COUNT(*) as test_count')
                ->selectRaw('SUM(r.success = 1) as success_count')
                ->selectRaw('SUM(r.success = 0) as fail_count')
                ->selectRaw('ROUND(SUM(r.success = 1) / NULLIF(COUNT(*), 0), 4) as success_rate')
                ->selectRaw('AVG(r.latency_ms) as avg_latency_ms')
                ->selectRaw('AVG(r.tcp_connect_ms) as avg_tcp_connect_ms')
                ->selectRaw('AVG(r.tls_hk_ms) as avg_tls_hk_ms')
                ->selectRaw('AVG(r.proxy_hk_ms) as avg_proxy_hk_ms')
                ->selectRaw('MAX(c.received_at) as last_received_at')
                ->groupBy('r.node_id', 'r.node_name', 'r.node_country', 'r.node_region', 'r.protocol');

            if (!$sortsAfterP95) {
                $query->orderBy($sortMap[$sortBy] ?? 'success_rate', $order);
            }

            $rows = $query->get();
            $nodeIds = $rows->pluck('node_id')->filter()->unique()->values()->all();
            $p95ByNodeId = $this->p95ValuesByField('firebase_event_vpn_probe_result', 'latency_ms', 'node_id', $nodeIds, $params);
            $topErrors = $this->topProbeErrorCodesByNodeIds($params, $nodeIds);

            $items = $rows->map(fn ($item) => (object) [
                'node_id' => $item->node_id,
                'node_name' => $item->node_name,
                'node_country' => $item->node_country,
                'node_region' => $item->node_region,
                'protocol' => $item->protocol,
                'test_count' => (int) $item->test_count,
                'success_count' => (int) $item->success_count,
                'fail_count' => (int) $item->fail_count,
                'success_rate' => $this->safeRate((int) $item->success_count, (int) $item->test_count),
                'avg_latency_ms' => (int) $item->avg_latency_ms,
                'p95_latency_ms' => $p95ByNodeId[(string) $item->node_id] ?? null,
                'avg_tcp_connect_ms' => (int) $item->avg_tcp_connect_ms,
                'avg_tls_hk_ms' => (int) $item->avg_tls_hk_ms,
                'avg_proxy_hk_ms' => (int) $item->avg_proxy_hk_ms,
                'top_error_code' => $topErrors[(string) $item->node_id] ?? null,
                'last_received_at' => $item->last_received_at,
            ]);

            if ($sortsAfterP95) {
                $items = $this->sortCollectionByField($items, 'p95_latency_ms', $order)->values();
            }

            $total = $items->count();
            $items = $items->forPage($page, $pageSize)->values();

            return [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'items' => $items,
            ];
        }, 60);
    }

    /**
     * Query Firebase VPN probe result detail rows with event and probe batch context.
     */
    public function probeResults(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $pageSize = min(max(1, (int) ($params['page_size'] ?? 20)), 200);
        $sortMap = [
            'received_at' => 'c.received_at',
            'result_index' => 'r.result_index',
            'latency_ms' => 'r.latency_ms',
            'tcp_connect_ms' => 'r.tcp_connect_ms',
            'tls_hk_ms' => 'r.tls_hk_ms',
            'proxy_hk_ms' => 'r.proxy_hk_ms',
            'timeout_ms' => 'r.timeout_ms',
            'id' => 'r.id',
        ];
        $sortBy = $params['sort_by'] ?? 'received_at';
        $order = ($params['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = DB::table('firebase_event_vpn_probe_result as r')
            ->join('firebase_event_vpn_probe as p', 'p.event_id', '=', 'r.event_id')
            ->join('firebase_event_common as c', 'c.event_id', '=', 'r.event_id');

        $query = $this->applyCommonFilters($query, $params, 'c');
        $query = $this->applyTimeFilters($query, $params, 'c');
        $this->applyProbeResultFilters($query, $params);

        $total = (clone $query)->count();
        $items = $query
            ->orderBy($sortMap[$sortBy] ?? $sortMap['received_at'], $order)
            ->orderBy('r.id', $order)
            ->forPage($page, $pageSize)
            ->get([
                'r.id',
                'r.event_id',
                'c.received_at',
                'c.event_time_ms',
                'c.app_id',
                'c.platform',
                'c.app_version',
                'c.device_id',
                'c.user_id',
                'c.user_country',
                'c.network_type',
                'p.probe_id',
                'p.probe_type',
                'p.probe_trigger',
                'r.result_index',
                'r.node_id',
                'r.node_name',
                'r.node_country',
                'r.node_region',
                'r.protocol',
                'r.success',
                'r.latency_ms',
                'r.tcp_connect_ms',
                'r.tls_hk_ms',
                'r.proxy_hk_ms',
                'r.error_code',
                'r.error_message',
                'r.timeout_ms',
            ]);

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'items' => $items,
        ];
    }

    public function apiErrorSummary(array $params): array
    {
        return $this->remember('api-error-summary', $params, function () use ($params) {
            $agg = $this->baseApiErrorQuery($params)
                ->selectRaw('COUNT(*) as api_error_count')
                ->selectRaw('COUNT(DISTINCT c.device_id) as affected_devices')
                ->selectRaw('SUM(http_status BETWEEN 500 AND 599) as http_5xx_count')
                ->selectRaw('SUM(http_status BETWEEN 400 AND 499) as http_4xx_count')
                ->selectRaw('SUM(error_code = "REQUEST_TIMEOUT") as timeout_count')
                ->selectRaw('SUM(business_code IS NOT NULL) as business_error_count')
                ->selectRaw('AVG(duration_ms) as avg_duration_ms')
                ->selectRaw('SUM(retry_count) as retry_count')
                ->first();

            return [
                'api_error_count' => (int) ($agg->api_error_count ?? 0),
                'affected_devices' => (int) ($agg->affected_devices ?? 0),
                'http_5xx_count' => (int) ($agg->http_5xx_count ?? 0),
                'http_4xx_count' => (int) ($agg->http_4xx_count ?? 0),
                'timeout_count' => (int) ($agg->timeout_count ?? 0),
                'business_error_count' => (int) ($agg->business_error_count ?? 0),
                'avg_duration_ms' => (int) ($agg->avg_duration_ms ?? 0),
                'retry_count' => (int) ($agg->retry_count ?? 0),
            ];
        }, 60);
    }

    public function apiErrorTrend(array $params): array
    {
        return $this->remember('api-error-trend', $params, function () use ($params) {
            $interval = $this->resolveInterval($params);
            $timeExpr = $this->timeBucketExpression($params, $interval);

            $items = $this->baseApiErrorQuery($params)
                ->selectRaw("{$timeExpr} as time")
                ->selectRaw('COUNT(*) as error_count')
                ->selectRaw('COUNT(DISTINCT c.device_id) as affected_devices')
                ->selectRaw('AVG(duration_ms) as avg_duration_ms')
                ->groupBy('time')
                ->orderBy('time')
                ->get();

            return [
                'interval' => $interval,
                'items' => $items,
            ];
        }, 60);
    }

    public function apiHttpStatusDistribution(array $params): array
    {
        return $this->remember('api-error-http-status', $params, function () use ($params) {
            $items = $this->baseApiErrorQuery($params)
                ->selectRaw('http_status, COUNT(*) as count')
                ->groupBy('http_status')
                ->orderByDesc('count')
                ->get();

            $total = $items->sum('count');
            $items = $items->map(fn ($item) => (object) [
                'http_status' => (int) $item->http_status,
                'count' => (int) $item->count,
                'ratio' => $this->safeRate((int) $item->count, (int) $total),
            ]);

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function apiPathRank(array $params): array
    {
        return $this->remember('api-error-api-rank', $params, function () use ($params) {
            $sortBy = $params['sort_by'] ?? 'error_count';
            $order = $params['order'] ?? 'desc';
            $limit = (int) ($params['limit'] ?? 20);

            $items = $this->baseApiErrorQuery($params)
                ->selectRaw('api_domain, api_path, http_method')
                ->selectRaw('COUNT(*) as error_count')
                ->selectRaw('MAX(http_status) as main_http_status')
                ->selectRaw('MAX(error_code) as main_error_code')
                ->selectRaw('AVG(duration_ms) as avg_duration_ms')
                ->selectRaw('COUNT(DISTINCT c.device_id) as affected_devices')
                ->groupBy('api_domain', 'api_path', 'http_method')
                ->orderBy($sortBy, $order)
                ->limit($limit)
                ->get();

            $items = $items->map(fn ($item, $index) => (object) [
                'rank' => $index + 1,
                'api_domain' => $item->api_domain,
                'api_path' => $item->api_path,
                'http_method' => $item->http_method,
                'error_count' => (int) $item->error_count,
                'main_http_status' => (int) $item->main_http_status,
                'main_error_code' => $item->main_error_code,
                'avg_duration_ms' => (int) $item->avg_duration_ms,
                'affected_devices' => (int) $item->affected_devices,
            ]);

            return [
                'items' => $items,
            ];
        }, 60);
    }

    public function events(array $params): array
    {
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['page_size'] ?? 20);

        $query = $this->baseCommonQuery($params);

        if (!empty($params['event_id'])) {
            $query->where('c.event_id', $params['event_id']);
        }
        if (!empty($params['device_id'])) {
            $query->where('c.device_id', $params['device_id']);
        }
        if (!empty($params['user_id'])) {
            $query->where('c.user_id', $params['user_id']);
        }
        if (!empty($params['node_id'])) {
            $query->whereExists(function ($sub) use ($params) {
                $sub->from('firebase_event_vpn_session')
                    ->whereColumn('firebase_event_vpn_session.event_id', 'c.event_id')
                    ->where('node_id', $params['node_id']);
            });
        }
        if (!empty($params['api_path'])) {
            $query->whereExists(function ($sub) use ($params) {
                $sub->from('firebase_event_server_api_error')
                    ->whereColumn('firebase_event_server_api_error.event_id', 'c.event_id')
                    ->where('api_path', $params['api_path']);
            });
        }
        if (!empty($params['trace_id'])) {
            $query->whereExists(function ($sub) use ($params) {
                $sub->from('firebase_event_server_api_error')
                    ->whereColumn('firebase_event_server_api_error.event_id', 'c.event_id')
                    ->where('trace_id', $params['trace_id']);
            });
        }
        if (!empty($params['error_code'])) {
            $query->whereExists(function ($sub) use ($params) {
                $sub->from('firebase_event_server_api_error')
                    ->whereColumn('firebase_event_server_api_error.event_id', 'c.event_id')
                    ->where('error_code', $params['error_code']);
            });
        }
        if (array_key_exists('success', $params)) {
            $query->whereExists(function ($sub) use ($params) {
                $sub->from('firebase_event_vpn_session')
                    ->whereColumn('firebase_event_vpn_session.event_id', 'c.event_id')
                    ->where('success', $params['success']);
            });
        }

        $total = (clone $query)->count();

        $items = $query
            ->orderByDesc('c.received_at')
            ->forPage($page, $pageSize)
            ->get([
                'c.event_id',
                'c.event_name',
                'c.received_at',
                'c.event_time_ms',
                'c.app_id',
                'c.platform',
                'c.app_version',
                'c.device_id',
                'c.user_id',
                'c.user_country',
                'c.network_type',
                'c.isp',
                'c.asn',
                'c.duplicate_count',
            ]);

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'items' => $items,
        ];
    }

    public function eventDetail(string $eventId): array
    {
        $common = DB::table('firebase_event_common')->where('event_id', $eventId)->first();
        if (!$common) {
            return [];
        }

        $extension = null;
        if ($common->event_name === 'app_open') {
            $extension = DB::table('firebase_event_app_open')->where('event_id', $eventId)->first();
        } elseif ($common->event_name === 'vpn_session') {
            $extension = DB::table('firebase_event_vpn_session')->where('event_id', $eventId)->first();
        } elseif ($common->event_name === 'vpn_probe') {
            $probe = DB::table('firebase_event_vpn_probe')->where('event_id', $eventId)->first();
            $results = DB::table('firebase_event_vpn_probe_result')->where('event_id', $eventId)->orderBy('result_index')->get();
            $extension = [
                'probe' => $probe,
                'results' => $results,
            ];
        } elseif ($common->event_name === 'server_api_error') {
            $extension = DB::table('firebase_event_server_api_error')->where('event_id', $eventId)->first();
        }

        return [
            'common' => $common,
            'extension' => $extension,
        ];
    }

    public function filterOptions(array $params): array
    {
        $query = $this->baseCommonQuery($params);

        $apps = (clone $query)->select('c.app_id')->distinct()->orderBy('c.app_id')->get()->pluck('app_id');
        $platforms = (clone $query)->select('c.platform')->distinct()->orderBy('c.platform')->get()->pluck('platform');
        $versions = (clone $query)->select('c.app_version')->distinct()->orderBy('c.app_version')->get()->pluck('app_version');
        $countries = (clone $query)->select('c.user_country')->distinct()->orderBy('c.user_country')->get()->pluck('user_country');
        $networkTypes = (clone $query)->select('c.network_type')->distinct()->orderBy('c.network_type')->get()->pluck('network_type');
        $isps = (clone $query)->select('c.isp')->distinct()->orderBy('c.isp')->get()->pluck('isp');
        $asns = (clone $query)->select('c.asn')->distinct()->orderBy('c.asn')->get()->pluck('asn');
        $eventNames = (clone $query)->select('c.event_name')->distinct()->orderBy('c.event_name')->get()->pluck('event_name');

        return [
            'apps' => $apps->map(fn ($item) => ['label' => $item, 'value' => $item])->values(),
            'platforms' => $platforms->map(fn ($item) => ['label' => ucfirst($item), 'value' => $item])->values(),
            'versions' => $versions->map(fn ($item) => ['label' => $item, 'value' => $item])->values(),
            'countries' => $countries->map(fn ($item) => ['label' => $item, 'value' => $item])->values(),
            'network_types' => $networkTypes->map(fn ($item) => ['label' => $item, 'value' => $item])->values(),
            'isps' => $isps->map(fn ($item) => ['label' => $item, 'value' => $item])->values(),
            'asns' => $asns->map(fn ($item) => ['label' => $item, 'value' => $item])->values(),
            'event_names' => $eventNames->map(fn ($item) => ['label' => $this->eventNameLabel($item), 'value' => $item])->values(),
        ];
    }

    private function baseCommonQuery(array $params): Builder
    {
        $query = DB::table('firebase_event_common as c');
        $query = $this->applyCommonFilters($query, $params, 'c');
        $query = $this->applyTimeFilters($query, $params, 'c');
        return $query;
    }

    private function baseSessionQuery(array $params): Builder
    {
        $query = DB::table('firebase_event_vpn_session as s')
            ->join('firebase_event_common as c', 'c.event_id', '=', 's.event_id');

        $query = $this->applyCommonFilters($query, $params, 'c');
        $query = $this->applyTimeFilters($query, $params, 'c');
        return $query;
    }

    private function baseProbeQuery(array $params): Builder
    {
        $query = DB::table('firebase_event_vpn_probe as p')
            ->join('firebase_event_common as c', 'c.event_id', '=', 'p.event_id');

        $query = $this->applyCommonFilters($query, $params, 'c');
        $query = $this->applyTimeFilters($query, $params, 'c');
        return $query;
    }

    private function baseProbeResultQuery(array $params): Builder
    {
        $query = DB::table('firebase_event_vpn_probe_result as r')
            ->join('firebase_event_common as c', 'c.event_id', '=', 'r.event_id');

        $query = $this->applyCommonFilters($query, $params, 'c');
        $query = $this->applyTimeFilters($query, $params, 'c');
        return $query;
    }

    private function baseAppOpenQuery(array $params): Builder
    {
        $query = DB::table('firebase_event_app_open as o')
            ->join('firebase_event_common as c', 'c.event_id', '=', 'o.event_id');

        $query = $this->applyCommonFilters($query, $params, 'c');
        $query = $this->applyTimeFilters($query, $params, 'c');
        return $query;
    }

    private function baseApiErrorQuery(array $params): Builder
    {
        $query = DB::table('firebase_event_server_api_error as e')
            ->join('firebase_event_common as c', 'c.event_id', '=', 'e.event_id');

        $query = $this->applyCommonFilters($query, $params, 'c');
        $query = $this->applyTimeFilters($query, $params, 'c');
        return $query;
    }

    private function applyProbeResultFilters(Builder $query, array $params): void
    {
        $map = [
            'event_id' => 'r.event_id',
            'probe_id' => 'p.probe_id',
            'node_id' => 'r.node_id',
            'node_name' => 'r.node_name',
            'node_country' => 'r.node_country',
            'node_region' => 'r.node_region',
            'protocol' => 'r.protocol',
            'error_code' => 'r.error_code',
        ];

        foreach ($map as $param => $column) {
            if (!empty($params[$param])) {
                $query->where($column, $params[$param]);
            }
        }

        if (array_key_exists('success', $params)) {
            $query->where('r.success', (bool) $params['success']);
        }
    }

    private function applyNodeFilters(Builder $query, array $params, string $alias): void
    {
        $map = [
            'node_id' => 'node_id',
            'node_name' => 'node_name',
            'node_country' => 'node_country',
            'node_region' => 'node_region',
            'protocol' => 'protocol',
        ];

        foreach ($map as $param => $column) {
            if (!empty($params[$param])) {
                $query->where($alias . '.' . $column, $params[$param]);
            }
        }
    }

    private function emptyNodeStatusItem(object $row): object
    {
        return (object) [
            'node_id' => $row->node_id,
            'node_name' => $row->node_name,
            'node_country' => $row->node_country,
            'node_region' => $row->node_region,
            'protocol' => $row->protocol,
            'diagnosis_status' => 'healthy',
            'diagnosis_priority' => 100,
            'sample_scope' => 'session_only',
            'rate_gap' => null,
            'session_count' => 0,
            'session_success_count' => 0,
            'session_fail_count' => 0,
            'session_success_rate' => 0.0,
            'avg_connect_ms' => 0,
            'p95_connect_ms' => null,
            'avg_duration_ms' => 0,
            'retry_session_count' => 0,
            'total_bytes' => 0,
            'session_top_error_code' => null,
            'last_session_received_at' => null,
            'probe_test_count' => 0,
            'probe_success_count' => 0,
            'probe_fail_count' => 0,
            'probe_success_rate' => 0.0,
            'avg_latency_ms' => 0,
            'p95_latency_ms' => null,
            'avg_tcp_connect_ms' => 0,
            'avg_tls_hk_ms' => 0,
            'avg_proxy_hk_ms' => 0,
            'probe_top_error_code' => null,
            'last_probe_received_at' => null,
        ];
    }

    private function nodeKeyFromRow(object $row): string
    {
        return implode('|', [
            (string) ($row->node_id ?? ''),
            (string) ($row->node_name ?? ''),
            (string) ($row->node_country ?? ''),
            (string) ($row->node_region ?? ''),
            (string) ($row->protocol ?? ''),
        ]);
    }

    private function diagnoseNodeStatus(object $item): array
    {
        $hasSession = $item->session_count > 0;
        $hasProbe = $item->probe_test_count > 0;
        $sessionRisk = $hasSession && $item->session_success_rate < 0.8;
        $probeRisk = $hasProbe && $item->probe_success_rate < 0.8;

        if ($hasSession && $hasProbe && !$probeRisk && $sessionRisk && ($item->rate_gap ?? 0) >= 0.3) {
            return ['connect_gap', 10];
        }
        if ($hasSession && $hasProbe && $sessionRisk && $probeRisk) {
            return ['dual_risk', 20];
        }
        if ($sessionRisk) {
            return ['session_risk', 30];
        }
        if ($probeRisk) {
            return ['probe_risk', 40];
        }
        if ($hasProbe && !$hasSession) {
            return ['probe_only', 50];
        }
        if ($hasSession && !$hasProbe) {
            return ['session_only', 60];
        }

        return ['healthy', 100];
    }

    private function p95SessionConnectMs(array $params): ?int
    {
        $query = $this->baseSessionQuery($params);
        $this->applyNodeFilters($query, $params, 's');

        $values = $query
            ->whereNotNull('s.connect_ms')
            ->orderBy('s.connect_ms')
            ->pluck('s.connect_ms')
            ->map(fn ($value) => (int) $value)
            ->all();

        return $this->percentile95($values);
    }

    private function p95ValuesByNodeKey(string $source, string $column, array $params): array
    {
        $alias = $source === 'probe' ? 'r' : 's';
        $query = $source === 'probe'
            ? $this->baseProbeResultQuery($params)
            : $this->baseSessionQuery($params);

        $this->applyNodeFilters($query, $params, $alias);

        $rows = $query
            ->whereNotNull($alias . '.' . $column)
            ->get([
                $alias . '.node_id',
                $alias . '.node_name',
                $alias . '.node_country',
                $alias . '.node_region',
                $alias . '.protocol',
                $alias . '.' . $column . ' as metric_value',
            ]);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$this->nodeKeyFromRow($row)][] = (int) $row->metric_value;
        }

        $result = [];
        foreach ($grouped as $key => $values) {
            $result[$key] = $this->percentile95($values);
        }

        return $result;
    }

    private function topSessionErrorCode(array $params): ?string
    {
        $query = $this->baseSessionQuery($params);
        $this->applyNodeFilters($query, $params, 's');

        $row = $query
            ->whereNotNull('s.error_code')
            ->selectRaw('s.error_code, COUNT(*) as count')
            ->groupBy('s.error_code')
            ->orderByDesc('count')
            ->first();

        return $row->error_code ?? null;
    }

    private function topErrorCodesByNodeKey(string $source, array $params): array
    {
        $alias = $source === 'probe' ? 'r' : 's';
        $query = $source === 'probe'
            ? $this->baseProbeResultQuery($params)
            : $this->baseSessionQuery($params);

        $this->applyNodeFilters($query, $params, $alias);

        $rows = $query
            ->whereNotNull($alias . '.error_code')
            ->selectRaw($alias . '.node_id, ' . $alias . '.node_name, ' . $alias . '.node_country, ' . $alias . '.node_region, ' . $alias . '.protocol')
            ->selectRaw($alias . '.error_code, COUNT(*) as count')
            ->groupBy(
                $alias . '.node_id',
                $alias . '.node_name',
                $alias . '.node_country',
                $alias . '.node_region',
                $alias . '.protocol',
                $alias . '.error_code'
            )
            ->orderBy($alias . '.node_id')
            ->orderByDesc('count')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $key = $this->nodeKeyFromRow($row);
            if (!array_key_exists($key, $result)) {
                $result[$key] = $row->error_code;
            }
        }

        return $result;
    }

    private function applyCommonFilters(Builder $query, array $params, string $alias = 'c'): Builder
    {
        $prefix = $alias ? $alias . '.' : '';

        $map = [
            'app_id' => 'app_id',
            'platform' => 'platform',
            'app_version' => 'app_version',
            'user_country' => 'user_country',
            'user_region' => 'user_region',
            'network_type' => 'network_type',
            'isp' => 'isp',
            'asn' => 'asn',
            'event_name' => 'event_name',
        ];

        foreach ($map as $param => $column) {
            if (!empty($params[$param])) {
                $query->where($prefix . $column, $params[$param]);
            }
        }

        return $query;
    }

    private function applyTimeFilters(Builder $query, array $params, string $alias = 'c'): Builder
    {
        $start = $params['start_time'] ?? null;
        $end = $params['end_time'] ?? null;
        $timeField = $params['time_field'] ?? 'received_at';

        if (!$start && !$end) {
            return $query;
        }

        if ($timeField === 'event_time') {
            $startMs = $start ? Carbon::parse($start)->getTimestampMs() : null;
            $endMs = $end ? Carbon::parse($end)->getTimestampMs() : null;
            if ($startMs !== null && $endMs !== null) {
                $query->whereBetween($alias . '.event_time_ms', [$startMs, $endMs]);
            } elseif ($startMs !== null) {
                $query->where($alias . '.event_time_ms', '>=', $startMs);
            } elseif ($endMs !== null) {
                $query->where($alias . '.event_time_ms', '<=', $endMs);
            }
        } else {
            if ($start && $end) {
                $query->whereBetween($alias . '.received_at', [$start, $end]);
            } elseif ($start) {
                $query->where($alias . '.received_at', '>=', $start);
            } elseif ($end) {
                $query->where($alias . '.received_at', '<=', $end);
            }
        }

        return $query;
    }

    private function resolveInterval(array $params): string
    {
        if (!empty($params['interval'])) {
            return $params['interval'];
        }

        if (!empty($params['start_time']) && !empty($params['end_time'])) {
            $start = Carbon::parse($params['start_time']);
            $end = Carbon::parse($params['end_time']);
            $hours = $start->diffInHours($end);
            $days = $start->diffInDays($end);

            if ($hours <= 6) {
                return '5m';
            }
            if ($hours <= 48) {
                return '1h';
            }
            if ($days <= 31) {
                return '1d';
            }
            return '1d';
        }

        return '1d';
    }

    private function timeBucketExpression(array $params, string $interval): string
    {
        $timeField = $params['time_field'] ?? 'received_at';

        $seconds = match ($interval) {
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            default => 86400,
        };

        if (DB::connection()->getDriverName() === 'sqlite') {
            $base = $timeField === 'event_time'
                ? '(c.event_time_ms / 1000)'
                : "strftime('%s', c.received_at)";

            return "datetime(CAST({$base} / {$seconds} AS INTEGER) * {$seconds}, 'unixepoch')";
        }

        $base = $timeField === 'event_time'
            ? 'FROM_UNIXTIME(c.event_time_ms / 1000)'
            : 'c.received_at';

        return "FROM_UNIXTIME(FLOOR(UNIX_TIMESTAMP({$base}) / {$seconds}) * {$seconds})";
    }

    private function safeRate(int $num, int $den): float
    {
        if ($den <= 0) {
            return 0.0;
        }
        return round($num / $den, 4);
    }

    private function avgReceiveDelayMs(array $params): int
    {
        $query = $this->baseCommonQuery($params);
        $avg = $query->selectRaw('AVG(TIMESTAMPDIFF(MICROSECOND, FROM_UNIXTIME(c.event_time_ms / 1000), c.received_at) / 1000) as avg_delay')
            ->value('avg_delay');

        return (int) ($avg ?? 0);
    }

    private function summaryCompare(array $params): array
    {
        if (empty($params['start_time']) || empty($params['end_time'])) {
            return [
                'total_events_rate' => 0,
                'active_devices_rate' => 0,
                'app_open_rate' => 0,
                'vpn_success_rate_diff' => 0,
                'probe_success_rate_diff' => 0,
                'api_error_rate' => 0,
            ];
        }

        $start = Carbon::parse($params['start_time']);
        $end = Carbon::parse($params['end_time']);
        $diffSeconds = $end->diffInSeconds($start);

        $prevParams = $params;
        $prevParams['end_time'] = $start->copy()->subSecond()->format('Y-m-d H:i:s');
        $prevParams['start_time'] = $start->copy()->subSeconds($diffSeconds)->format('Y-m-d H:i:s');

        $current = $this->dashboardSummary(array_merge($params, ['compare' => true]));
        $prev = $this->dashboardSummary(array_merge($prevParams, ['compare' => true]));

        return [
            'total_events_rate' => $this->safeRate($current['total_events'] - $prev['total_events'], $prev['total_events']),
            'active_devices_rate' => $this->safeRate($current['active_devices'] - $prev['active_devices'], $prev['active_devices']),
            'app_open_rate' => $this->safeRate($current['app_open_count'] - $prev['app_open_count'], $prev['app_open_count']),
            'vpn_success_rate_diff' => round($current['vpn_success_rate'] - $prev['vpn_success_rate'], 4),
            'probe_success_rate_diff' => round($current['probe_success_rate'] - $prev['probe_success_rate'], 4),
            'api_error_rate' => $this->safeRate($current['api_error_count'] - $prev['api_error_count'], $prev['api_error_count']),
        ];
    }

    private function p95Value(string $table, string $column, array $params, ?string $bucket = null, ?string $interval = null, array $extraFilters = []): ?int
    {
        $query = DB::table("{$table} as t")
            ->join('firebase_event_common as c', 'c.event_id', '=', 't.event_id');

        $query = $this->applyCommonFilters($query, $params, 'c');
        $query = $this->applyTimeFilters($query, $params, 'c');

        foreach ($extraFilters as $field => $value) {
            if ($value !== null && $value !== '') {
                $query->where('t.' . $field, $value);
            }
        }

        if ($bucket && $interval) {
            $range = $this->bucketRange($bucket, $interval);
            if ($range) {
                $timeField = $params['time_field'] ?? 'received_at';
                if ($timeField === 'event_time') {
                    $query->whereBetween('c.event_time_ms', [$range['start_ms'], $range['end_ms']]);
                } else {
                    $query->whereBetween('c.received_at', [$range['start'], $range['end']]);
                }
            }
        }

        $count = (clone $query)->whereNotNull($column)->count();
        if ($count === 0) {
            return null;
        }

        $offset = (int) floor($count * 0.95);
        $value = (clone $query)
            ->whereNotNull($column)
            ->orderBy($column)
            ->offset($offset)
            ->limit(1)
            ->value($column);

        return $value !== null ? (int) $value : null;
    }

    /**
     * Calculate exact P95 by time bucket in one query and group in PHP.
     */
    private function p95ValuesByBucket(string $table, string $column, array $params, string $interval): array
    {
        $timeExpr = $this->timeBucketExpression($params, $interval);
        $query = DB::table("{$table} as t")
            ->join('firebase_event_common as c', 'c.event_id', '=', 't.event_id');

        $query = $this->applyCommonFilters($query, $params, 'c');
        $query = $this->applyTimeFilters($query, $params, 'c');

        $rows = $query
            ->whereNotNull('t.' . $column)
            ->selectRaw("{$timeExpr} as bucket")
            ->selectRaw("t.{$column} as metric_value")
            ->get();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->bucket][] = (int) $row->metric_value;
        }

        $result = [];
        foreach ($grouped as $bucket => $values) {
            $result[$bucket] = $this->percentile95($values);
        }

        return $result;
    }

    /**
     * Calculate exact P95 by a detail-table field in one query.
     */
    private function p95ValuesByField(string $table, string $column, string $field, array $values, array $params): array
    {
        $values = array_values(array_filter(array_unique($values), fn ($value) => $value !== null && $value !== ''));
        if (empty($values)) {
            return [];
        }

        $query = DB::table("{$table} as t")
            ->join('firebase_event_common as c', 'c.event_id', '=', 't.event_id');

        $query = $this->applyCommonFilters($query, $params, 'c');
        $query = $this->applyTimeFilters($query, $params, 'c');

        $rows = $query
            ->whereIn('t.' . $field, $values)
            ->whereNotNull('t.' . $column)
            ->get([
                't.' . $field . ' as group_key',
                't.' . $column . ' as metric_value',
            ]);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->group_key][] = (int) $row->metric_value;
        }

        $result = [];
        foreach ($grouped as $groupKey => $metricValues) {
            $result[$groupKey] = $this->percentile95($metricValues);
        }

        return $result;
    }

    private function percentile95(array $values): ?int
    {
        if (empty($values)) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $offset = (int) floor(count($values) * 0.95);
        $offset = min($offset, count($values) - 1);

        return (int) $values[$offset];
    }

    private function sortCollectionByField($items, string $field, string $order)
    {
        return $items->sort(function ($left, $right) use ($field, $order) {
            $leftValue = $left->{$field} ?? null;
            $rightValue = $right->{$field} ?? null;

            if ($leftValue === null && $rightValue === null) {
                return 0;
            }
            if ($leftValue === null) {
                return 1;
            }
            if ($rightValue === null) {
                return -1;
            }

            $compare = $leftValue <=> $rightValue;
            return $order === 'asc' ? $compare : -$compare;
        });
    }

    private function bucketRange(string $bucket, string $interval): ?array
    {
        try {
            $start = Carbon::parse($bucket);
        } catch (\Exception $e) {
            return null;
        }

        $end = match ($interval) {
            '5m' => $start->copy()->addMinutes(5),
            '15m' => $start->copy()->addMinutes(15),
            '1h' => $start->copy()->addHour(),
            default => $start->copy()->addDay(),
        };

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
            'start_ms' => $start->getTimestampMs(),
            'end_ms' => $end->getTimestampMs(),
        ];
    }

    private function topErrorCodesByProtocols(array $params, array $protocols): array
    {
        $protocols = array_values(array_filter(array_unique($protocols), fn ($protocol) => $protocol !== null && $protocol !== ''));
        if (empty($protocols)) {
            return [];
        }

        $rows = $this->baseSessionQuery($params)
            ->whereIn('protocol', $protocols)
            ->whereNotNull('error_code')
            ->selectRaw('protocol, error_code, COUNT(*) as count')
            ->groupBy('protocol', 'error_code')
            ->orderBy('protocol')
            ->orderByDesc('count')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $protocol = (string) $row->protocol;
            if (!array_key_exists($protocol, $result)) {
                $result[$protocol] = $row->error_code;
            }
        }

        return $result;
    }

    private function topProbeErrorCodesByNodeIds(array $params, array $nodeIds): array
    {
        $nodeIds = array_values(array_filter(array_unique($nodeIds), fn ($nodeId) => $nodeId !== null && $nodeId !== ''));
        if (empty($nodeIds)) {
            return [];
        }

        $rows = $this->baseProbeResultQuery($params)
            ->whereIn('node_id', $nodeIds)
            ->whereNotNull('error_code')
            ->selectRaw('node_id, error_code, COUNT(*) as count')
            ->groupBy('node_id', 'error_code')
            ->orderBy('node_id')
            ->orderByDesc('count')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $nodeId = (string) $row->node_id;
            if (!array_key_exists($nodeId, $result)) {
                $result[$nodeId] = $row->error_code;
            }
        }

        return $result;
    }

    private function eventNameLabel(string $eventName): string
    {
        return match ($eventName) {
            'app_open' => 'App 打开',
            'vpn_session' => 'VPN 连接',
            'vpn_probe' => '节点测速',
            'server_api_error' => 'API 错误',
            default => $eventName,
        };
    }

    private function remember(string $name, array $params, callable $callback, int $ttl = 60, array $exclude = []): array
    {
        $cacheParams = $this->cacheParams($params, $exclude);
        $key = 'firebase_analytics:' . $name . ':' . md5(json_encode($cacheParams));

        return Cache::remember($key, $ttl, function () use ($callback) {
            return $callback();
        });
    }

    private function cacheParams(array $params, array $exclude = []): array
    {
        foreach ($exclude as $key) {
            unset($params[$key]);
        }

        ksort($params);
        return $params;
    }
}
