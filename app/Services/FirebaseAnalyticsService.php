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
        $interval = $this->resolveInterval($params);
        $timeExpr = $this->timeBucketExpression($params, $interval);

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

        $items = $items->map(function ($item) use ($params, $interval) {
            $bucket = $item->time;
            $p95 = $this->p95Value('firebase_event_vpn_session', 'connect_ms', $params, $bucket, $interval);
            $item->p95_connect_ms = $p95;
            $item->success_rate = $this->safeRate((int) $item->success_count, (int) $item->session_count);
            return $item;
        });

        return [
            'interval' => $interval,
            'items' => $items,
        ];
    }

    public function regionQuality(array $params): array
    {
        return $this->remember('region-quality', $params, function () use ($params) {
            $sortBy = $params['sort_by'] ?? 'event_count';
            $order = $params['order'] ?? 'desc';
            $limit = (int) ($params['limit'] ?? 50);

            $items = $this->baseCommonQuery($params)
                ->selectRaw('user_country, user_region')
                ->selectRaw('COUNT(*) as event_count')
                ->selectRaw('COUNT(DISTINCT device_id) as active_devices')
                ->selectRaw('SUM(event_name = "vpn_session") as vpn_session_count')
                ->selectRaw('SUM(event_name = "server_api_error") as api_error_count')
                ->groupBy('user_country', 'user_region')
                ->orderBy($sortBy, $order)
                ->limit($limit)
                ->get();

            $items = $items->map(function ($item) use ($params) {
                $sessionAgg = $this->baseSessionQuery($params)
                    ->where('c.user_country', $item->user_country)
                    ->where('c.user_region', $item->user_region)
                    ->selectRaw('COUNT(*) as total')
                    ->selectRaw('SUM(success = 1) as success_count')
                    ->selectRaw('AVG(connect_ms) as avg_connect_ms')
                    ->first();

                $item->vpn_success_rate = $this->safeRate((int) ($sessionAgg->success_count ?? 0), (int) ($sessionAgg->total ?? 0));
                $item->avg_connect_ms = (int) ($sessionAgg->avg_connect_ms ?? 0);
                return $item;
            });

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
            $sortBy = $params['sort_by'] ?? 'session_count';
            $order = $params['order'] ?? 'desc';
            $limit = (int) ($params['limit'] ?? 20);

            if ($source === 'probe') {
                $items = $this->baseProbeResultQuery($params)
                    ->selectRaw('node_id, node_name, node_country, protocol')
                    ->selectRaw('COUNT(*) as test_count')
                    ->selectRaw('SUM(success = 1) as success_count')
                    ->selectRaw('AVG(latency_ms) as avg_latency_ms')
                    ->selectRaw('AVG(tcp_connect_ms) as avg_tcp_connect_ms')
                    ->selectRaw('AVG(tls_hk_ms) as avg_tls_hk_ms')
                    ->selectRaw('AVG(proxy_hk_ms) as avg_proxy_hk_ms')
                    ->groupBy('node_id', 'node_name', 'node_country', 'protocol')
                    ->orderBy($sortBy, $order)
                    ->limit($limit)
                    ->get();

                $items = $items->map(function ($item, $index) use ($params) {
                    $p95 = $this->p95Value('firebase_event_vpn_probe_result', 'latency_ms', $params, null, null, [
                        'node_id' => $item->node_id,
                    ]);
                    return (object) [
                        'rank' => $index + 1,
                        'node_id' => $item->node_id,
                        'node_name' => $item->node_name,
                        'node_country' => $item->node_country,
                        'node_region' => null,
                        'protocol' => $item->protocol,
                        'session_count' => (int) $item->test_count,
                        'success_count' => (int) $item->success_count,
                        'success_rate' => $this->safeRate((int) $item->success_count, (int) $item->test_count),
                        'avg_connect_ms' => (int) $item->avg_tcp_connect_ms,
                        'p95_connect_ms' => $p95,
                        'avg_duration_ms' => (int) $item->avg_latency_ms,
                        'total_bytes' => 0,
                        'top_error_code' => $this->topProbeErrorCode($params, $item->node_id),
                    ];
                });
            } else {
                $items = $this->baseSessionQuery($params)
                    ->selectRaw('node_id, node_name, node_country, node_region, protocol')
                    ->selectRaw('COUNT(*) as session_count')
                    ->selectRaw('SUM(success = 1) as success_count')
                    ->selectRaw('AVG(connect_ms) as avg_connect_ms')
                    ->selectRaw('AVG(duration_ms) as avg_duration_ms')
                    ->selectRaw('SUM(total_bytes) as total_bytes')
                    ->groupBy('node_id', 'node_name', 'node_country', 'node_region', 'protocol')
                    ->orderBy($sortBy, $order)
                    ->limit($limit)
                    ->get();

                $items = $items->map(function ($item, $index) use ($params) {
                    $p95 = $this->p95Value('firebase_event_vpn_session', 'connect_ms', $params, null, null, [
                        'node_id' => $item->node_id,
                    ]);
                    return (object) [
                        'rank' => $index + 1,
                        'node_id' => $item->node_id,
                        'node_name' => $item->node_name,
                        'node_country' => $item->node_country,
                        'node_region' => $item->node_region,
                        'protocol' => $item->protocol,
                        'session_count' => (int) $item->session_count,
                        'success_count' => (int) $item->success_count,
                        'success_rate' => $this->safeRate((int) $item->success_count, (int) $item->session_count),
                        'avg_connect_ms' => (int) $item->avg_connect_ms,
                        'p95_connect_ms' => $p95,
                        'avg_duration_ms' => (int) $item->avg_duration_ms,
                        'total_bytes' => (int) $item->total_bytes,
                        'top_error_code' => $this->topErrorCodeByProtocol($params, $item->protocol),
                    ];
                });
            }

            return [
                'source' => $source,
                'items' => $items,
            ];
        }, 60);
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

            $items = $this->baseAppOpenQuery($params)
                ->selectRaw("{$timeExpr} as time")
                ->selectRaw('COUNT(*) as open_count')
                ->selectRaw('COUNT(DISTINCT c.device_id) as active_devices')
                ->selectRaw('AVG(launch_ms) as avg_launch_ms')
                ->groupBy('time')
                ->orderBy('time')
                ->get();

            $items = $items->map(function ($item) use ($params, $interval) {
                $item->p95_launch_ms = $this->p95Value('firebase_event_app_open', 'launch_ms', $params, $item->time, $interval);
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

            $items = $items->map(fn ($item) => (object) [
                'protocol' => $item->protocol,
                'session_count' => (int) $item->session_count,
                'success_rate' => $this->safeRate((int) $item->success_count, (int) $item->session_count),
                'avg_connect_ms' => (int) $item->avg_connect_ms,
                'avg_duration_ms' => (int) $item->avg_duration_ms,
                'top_error_code' => $this->topErrorCodeByProtocol($params, $item->protocol),
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
            $order = $params['order'] ?? 'desc';
            $limit = (int) ($params['limit'] ?? 20);

            $items = $this->baseProbeResultQuery($params)
                ->selectRaw('node_id, node_name, node_country, protocol')
                ->selectRaw('COUNT(*) as test_count')
                ->selectRaw('SUM(success = 1) as success_count')
                ->selectRaw('AVG(latency_ms) as avg_latency_ms')
                ->selectRaw('AVG(tcp_connect_ms) as avg_tcp_connect_ms')
                ->selectRaw('AVG(tls_hk_ms) as avg_tls_hk_ms')
                ->selectRaw('AVG(proxy_hk_ms) as avg_proxy_hk_ms')
                ->groupBy('node_id', 'node_name', 'node_country', 'protocol')
                ->orderBy($sortBy, $order)
                ->limit($limit)
                ->get();

            $items = $items->map(fn ($item, $index) => (object) [
                'rank' => $index + 1,
                'node_id' => $item->node_id,
                'node_name' => $item->node_name,
                'node_country' => $item->node_country,
                'protocol' => $item->protocol,
                'test_count' => (int) $item->test_count,
                'success_rate' => $this->safeRate((int) $item->success_count, (int) $item->test_count),
                'avg_latency_ms' => (int) $item->avg_latency_ms,
                'p95_latency_ms' => $this->p95Value('firebase_event_vpn_probe_result', 'latency_ms', $params, null, null, [
                    'node_id' => $item->node_id,
                ]),
                'avg_tcp_connect_ms' => (int) $item->avg_tcp_connect_ms,
                'avg_tls_hk_ms' => (int) $item->avg_tls_hk_ms,
                'avg_proxy_hk_ms' => (int) $item->avg_proxy_hk_ms,
                'top_error_code' => $this->topProbeErrorCode($params, $item->node_id),
            ]);

            return [
                'items' => $items,
            ];
        }, 60);
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
        $base = $timeField === 'event_time'
            ? 'FROM_UNIXTIME(c.event_time_ms / 1000)'
            : 'c.received_at';

        $seconds = match ($interval) {
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            default => 86400,
        };

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

    private function topErrorCodeByProtocol(array $params, ?string $protocol): ?string
    {
        if (!$protocol) {
            return null;
        }

        return $this->baseSessionQuery($params)
            ->where('protocol', $protocol)
            ->whereNotNull('error_code')
            ->selectRaw('error_code, COUNT(*) as count')
            ->groupBy('error_code')
            ->orderByDesc('count')
            ->value('error_code');
    }

    private function topProbeErrorCode(array $params, ?string $nodeId): ?string
    {
        if (!$nodeId) {
            return null;
        }

        return $this->baseProbeResultQuery($params)
            ->where('node_id', $nodeId)
            ->whereNotNull('error_code')
            ->selectRaw('error_code, COUNT(*) as count')
            ->groupBy('error_code')
            ->orderByDesc('count')
            ->value('error_code');
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
