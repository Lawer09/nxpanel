<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class NodeServerReportService
{
    public const REDIS_RAW_PREFIX = 'node_server_report:raw:';
    private const DEFAULT_NODE_ID = 0;
    private const DEFAULT_NODE_TYPE = 'unknown';
    private const DEFAULT_NODE_HOST = 'n.n.n.n';
    private const DEFAULT_NODE_PUBLIC_IP = '0.0.0.0';

    public static function enabled(): bool
    {
        return (bool) env('NODE_SERVER_REPORT_ENABLED', true);
    }

    public static function pushRawPayload(array $payload, int $reportAtMs): void
    {
        if (!self::enabled()) {
            return;
        }

        $key = self::bucketKeyByTimestampMs($reportAtMs);

        Redis::pipeline(function ($pipe) use ($key, $payload) {
            $pipe->rpush($key, json_encode($payload, JSON_UNESCAPED_UNICODE));
            $pipe->expire($key, 3600);
        });
    }

    public static function bucketKeyByTimestampMs(int $timestampMs): string
    {
        $time = Carbon::createFromTimestampMsUTC($timestampMs)->setTimezone('Asia/Shanghai');
        return self::bucketKeyAtUtc8($time);
    }

    public static function bucketKeyAtUtc8(Carbon $time): string
    {
        $bucketMinute = (int) floor(((int) $time->minute) / 5) * 5;
        $bucket = $time->copy()->second(0)->minute($bucketMinute)->format('YmdHi');

        return self::REDIS_RAW_PREFIX . $bucket;
    }

    public static function readBucket(string $bucketKey, int $batchSize = 10000): array
    {
        $jsonArray = Redis::lrange($bucketKey, 0, $batchSize - 1);
        if (empty($jsonArray)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($json) {
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : null;
        }, $jsonArray)));
    }

    public static function deleteBucket(string $bucketKey): void
    {
        Redis::del($bucketKey);
    }

    public static function restoreBucket(string $bucketKey, array $payloads): void
    {
        if (empty($payloads)) {
            return;
        }

        Redis::pipeline(function ($pipe) use ($bucketKey, $payloads) {
            foreach ($payloads as $payload) {
                $pipe->rpush($bucketKey, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
            $pipe->expire($bucketKey, 3600);
        });
    }

    public function processBatch(array $payloads): array
    {
        $normalized = [];
        $nodeIds = [];
        $userIds = [];

        foreach ($payloads as $payload) {
            $item = $this->normalizePayload($payload);
            if ($item === null) {
                continue;
            }

            $normalized[] = $item;
            if ($item['node_id'] > 0) {
                $nodeIds[$item['node_id']] = $item['node_id'];
            }

            foreach ($item['traffic_entries'] as $entry) {
                if ($entry['user_id'] > 0) {
                    $userIds[$entry['user_id']] = $entry['user_id'];
                }
            }
        }

        if (empty($normalized)) {
            return ['payloads' => 0, 'node_rows' => 0, 'user_rows' => 0];
        }

        $nodeMetaMap = $this->loadNodeMeta(array_values($nodeIds));
        $userMetaMap = $this->loadUserMeta(array_values($userIds));

        $nodeSysAgg = [];
        $nodeTrafficByApp = [];
        $userAgg = [];

        foreach ($normalized as $item) {
            $nodeMeta = $nodeMetaMap[$item['node_id']] ?? [
                'node_type' => $item['node_type'],
                'node_host' => self::DEFAULT_NODE_HOST,
                'node_public_ip' => self::DEFAULT_NODE_PUBLIC_IP,
            ];

            $nodeKey = implode('|', [$item['date'], $item['hour'], $item['node_id']]);

            // 1. System metrics per node (from payload status/metrics)
            if (!isset($nodeSysAgg[$nodeKey])) {
                $nodeSysAgg[$nodeKey] = [
                    'date' => $item['date'],
                    'hour' => $item['hour'],
                    'node_id' => $item['node_id'],
                    'node_type' => (string) ($nodeMeta['node_type'] ?? self::DEFAULT_NODE_TYPE),
                    'node_host' => (string) ($nodeMeta['node_host'] ?? self::DEFAULT_NODE_HOST),
                    'node_public_ip' => (string) ($nodeMeta['node_public_ip'] ?? self::DEFAULT_NODE_PUBLIC_IP),
                    'sum_cpu_usage' => 0.0,
                    'sum_mem_usage' => 0.0,
                    'sum_disk_usage' => 0.0,
                    'sum_inbound_speed' => 0.0,
                    'sum_outbound_speed' => 0.0,
                    'sum_tcp_connections' => 0.0,
                    'sum_alive_users' => 0.0,
                    'max_cpu_usage' => 0.0,
                    'max_mem_usage' => 0.0,
                    'max_inbound_speed' => 0.0,
                    'max_outbound_speed' => 0.0,
                    'max_tcp_connections' => 0.0,
                    'max_alive_users' => 0.0,
                    'compute_count' => 0,
                ];
            }

            $nodeSysAgg[$nodeKey]['node_type'] = $nodeSysAgg[$nodeKey]['node_type'] !== ''
                ? $nodeSysAgg[$nodeKey]['node_type']
                : (string) ($nodeMeta['node_type'] ?? self::DEFAULT_NODE_TYPE);
            $nodeSysAgg[$nodeKey]['node_host'] = $nodeSysAgg[$nodeKey]['node_host'] !== ''
                ? $nodeSysAgg[$nodeKey]['node_host']
                : (string) ($nodeMeta['node_host'] ?? self::DEFAULT_NODE_HOST);
            $nodeSysAgg[$nodeKey]['node_public_ip'] = $nodeSysAgg[$nodeKey]['node_public_ip'] !== ''
                ? $nodeSysAgg[$nodeKey]['node_public_ip']
                : (string) ($nodeMeta['node_public_ip'] ?? self::DEFAULT_NODE_PUBLIC_IP);

            $nodeSysAgg[$nodeKey]['sum_cpu_usage'] += $item['cpu_usage'];
            $nodeSysAgg[$nodeKey]['sum_mem_usage'] += $item['mem_usage'];
            $nodeSysAgg[$nodeKey]['sum_disk_usage'] += $item['disk_usage'];
            $nodeSysAgg[$nodeKey]['sum_inbound_speed'] += $item['inbound_speed'];
            $nodeSysAgg[$nodeKey]['sum_outbound_speed'] += $item['outbound_speed'];
            $nodeSysAgg[$nodeKey]['sum_tcp_connections'] += $item['tcp_connections'];
            $nodeSysAgg[$nodeKey]['sum_alive_users'] += $item['alive_users'];
            $nodeSysAgg[$nodeKey]['max_cpu_usage'] = max($nodeSysAgg[$nodeKey]['max_cpu_usage'], $item['cpu_usage']);
            $nodeSysAgg[$nodeKey]['max_mem_usage'] = max($nodeSysAgg[$nodeKey]['max_mem_usage'], $item['mem_usage']);
            $nodeSysAgg[$nodeKey]['max_inbound_speed'] = max($nodeSysAgg[$nodeKey]['max_inbound_speed'], $item['inbound_speed']);
            $nodeSysAgg[$nodeKey]['max_outbound_speed'] = max($nodeSysAgg[$nodeKey]['max_outbound_speed'], $item['outbound_speed']);
            $nodeSysAgg[$nodeKey]['max_tcp_connections'] = max($nodeSysAgg[$nodeKey]['max_tcp_connections'], $item['tcp_connections']);
            $nodeSysAgg[$nodeKey]['max_alive_users'] = max($nodeSysAgg[$nodeKey]['max_alive_users'], $item['alive_users']);
            $nodeSysAgg[$nodeKey]['compute_count']++;

            // 2. Traffic per app from traffic entries + userMetaMap
            foreach ($item['traffic_entries'] as $entry) {
                $uid = $entry['user_id'];
                if ($uid <= 0) {
                    continue;
                }

                $meta = $userMetaMap[$uid] ?? ['app_id' => '', 'app_version' => ''];
                $appId = (string) ($meta['app_id'] ?? '');
                $appVersion = (string) ($meta['app_version'] ?? '');
                $appKey = $nodeKey . '|' . $appId . '|' . $appVersion;

                if (!isset($nodeTrafficByApp[$appKey])) {
                    $nodeTrafficByApp[$appKey] = [
                        'date' => $item['date'],
                        'hour' => $item['hour'],
                        'node_id' => $item['node_id'],
                        'app_id' => $appId,
                        'app_version' => $appVersion,
                        'traffic_upload' => 0,
                        'traffic_download' => 0,
                        'compute_count' => 0,
                    ];
                }

                $nodeTrafficByApp[$appKey]['traffic_upload'] += $entry['upload'];
                $nodeTrafficByApp[$appKey]['traffic_download'] += $entry['download'];
                $nodeTrafficByApp[$appKey]['compute_count']++;
            }

            // 3. Per-user traffic (unchanged)
            foreach ($item['traffic_entries'] as $entry) {
                if ($entry['user_id'] <= 0) {
                    continue;
                }

                $userMeta = $userMetaMap[$entry['user_id']] ?? [
                    'app_id' => '',
                    'app_version' => '',
                    'country' => '',
                ];

                $userKey = implode('|', [$item['date'], $item['hour'], $item['node_id'], $entry['user_id']]);
                if (!isset($userAgg[$userKey])) {
                    $userAgg[$userKey] = [
                        'date' => $item['date'],
                        'hour' => $item['hour'],
                        'node_id' => $item['node_id'],
                        'user_id' => $entry['user_id'],
                        'app_id' => (string) ($userMeta['app_id'] ?? ''),
                        'app_version' => (string) ($userMeta['app_version'] ?? ''),
                        'country' => (string) ($userMeta['country'] ?? ''),
                        'traffic_upload' => 0,
                        'traffic_download' => 0,
                        'compute_count' => 0,
                    ];
                }

                $userAgg[$userKey]['traffic_upload'] += $entry['upload'];
                $userAgg[$userKey]['traffic_download'] += $entry['download'];
                $userAgg[$userKey]['compute_count']++;
            }
        }

        DB::transaction(function () use ($nodeSysAgg, $nodeTrafficByApp, $userAgg) {
            $now = now();

            // Merge nodeSysAgg + nodeTrafficByApp → per-app rows in v3_node_server_report_node
            foreach ($nodeSysAgg as $nodeKey => $sysRow) {
                $sysCount = max(1, (int) $sysRow['compute_count']);
                $avgCpu = round($sysRow['sum_cpu_usage'] / $sysCount, 6);
                $avgMem = round($sysRow['sum_mem_usage'] / $sysCount, 6);
                $avgDisk = round($sysRow['sum_disk_usage'] / $sysCount, 6);
                $avgInbound = round($sysRow['sum_inbound_speed'] / $sysCount, 6);
                $avgOutbound = round($sysRow['sum_outbound_speed'] / $sysCount, 6);
                $avgTcp = round($sysRow['sum_tcp_connections'] / $sysCount, 6);
                $avgAlive = round($sysRow['sum_alive_users'] / $sysCount, 6);

                $nodePrefix = $nodeKey . '|';
                $appRows = [];
                foreach ($nodeTrafficByApp as $appKey => $trafficRow) {
                    if (str_starts_with($appKey, $nodePrefix)) {
                        $appRows[] = $trafficRow;
                    }
                }

                if (empty($appRows)) {
                    $appRows[] = [
                        'app_id' => '',
                        'app_version' => '',
                        'traffic_upload' => 0,
                        'traffic_download' => 0,
                        'compute_count' => $sysCount,
                    ];
                }

                foreach ($appRows as $trafficRow) {
                    DB::table('v3_node_server_report_node')->updateOrInsert(
                        [
                            'date' => $sysRow['date'],
                            'hour' => $sysRow['hour'],
                            'node_id' => $sysRow['node_id'],
                            'app_id' => $trafficRow['app_id'],
                            'app_version' => $trafficRow['app_version'],
                        ],
                        [
                            'node_type' => $sysRow['node_type'],
                            'node_host' => $sysRow['node_host'],
                            'node_public_ip' => $sysRow['node_public_ip'],
                            'traffic_upload' => DB::raw('traffic_upload + ' . (int) $trafficRow['traffic_upload']),
                            'traffic_download' => DB::raw('traffic_download + ' . (int) $trafficRow['traffic_download']),
                            'avg_cpu_usage' => $avgCpu,
                            'avg_mem_usage' => $avgMem,
                            'max_cpu_usage' => DB::raw('GREATEST(max_cpu_usage, ' . (float) $sysRow['max_cpu_usage'] . ')'),
                            'max_mem_usage' => DB::raw('GREATEST(max_mem_usage, ' . (float) $sysRow['max_mem_usage'] . ')'),
                            'avg_disk_usage' => $avgDisk,
                            'avg_inbound_speed' => $avgInbound,
                            'avg_outbound_speed' => $avgOutbound,
                            'max_inbound_speed' => DB::raw('GREATEST(max_inbound_speed, ' . (float) $sysRow['max_inbound_speed'] . ')'),
                            'max_outbound_speed' => DB::raw('GREATEST(max_outbound_speed, ' . (float) $sysRow['max_outbound_speed'] . ')'),
                            'avg_tcp_connections' => $avgTcp,
                            'max_tcp_connections' => DB::raw('GREATEST(max_tcp_connections, ' . (float) $sysRow['max_tcp_connections'] . ')'),
                            'avg_alive_users' => $avgAlive,
                            'max_alive_users' => DB::raw('GREATEST(max_alive_users, ' . (float) $sysRow['max_alive_users'] . ')'),
                            'compute_count' => DB::raw('compute_count + ' . (int) $trafficRow['compute_count']),
                            'updated_at' => $now,
                        ]
                    );
                }
            }

            foreach ($userAgg as $row) {
                $existing = DB::table('v3_node_server_report_user')
                    ->where('date', $row['date'])
                    ->where('hour', $row['hour'])
                    ->where('node_id', $row['node_id'])
                    ->where('user_id', $row['user_id'])
                    ->first();

                if ($existing) {
                    DB::table('v3_node_server_report_user')
                        ->where('id', $existing->id)
                        ->update([
                            'app_id' => $row['app_id'] !== '' ? $row['app_id'] : (string) $existing->app_id,
                            'app_version' => $row['app_version'] !== '' ? $row['app_version'] : (string) $existing->app_version,
                            'country' => $row['country'] !== '' ? $row['country'] : (string) $existing->country,
                            'traffic_upload' => ((int) $existing->traffic_upload) + $row['traffic_upload'],
                            'traffic_download' => ((int) $existing->traffic_download) + $row['traffic_download'],
                            'compute_count' => ((int) $existing->compute_count) + $row['compute_count'],
                            'updated_at' => $now,
                        ]);
                    continue;
                }

                DB::table('v3_node_server_report_user')->insert([
                    'date' => $row['date'],
                    'hour' => $row['hour'],
                    'node_id' => $row['node_id'],
                    'user_id' => $row['user_id'],
                    'app_id' => $row['app_id'],
                    'app_version' => $row['app_version'],
                    'country' => $row['country'],
                    'traffic_upload' => $row['traffic_upload'],
                    'traffic_download' => $row['traffic_download'],
                    'compute_count' => $row['compute_count'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return [
            'payloads' => count($normalized),
            'node_rows' => count($nodeSysAgg),
            'node_app_rows' => count($nodeTrafficByApp),
            'user_rows' => count($userAgg),
        ];
    }

    private function normalizePayload(array $payload): ?array
    {
        $nodeId = max(self::DEFAULT_NODE_ID, (int) ($payload['node_id'] ?? $payload['nodeId'] ?? self::DEFAULT_NODE_ID));

        $reportAtMs = $this->normalizeTimestampMs($payload['report_at_ms'] ?? $payload['reported_at'] ?? null)
            ?? now()->getTimestampMs();

        $time = Carbon::createFromTimestampMsUTC($reportAtMs)->setTimezone('Asia/Shanghai');
        $status = is_array($payload['status'] ?? null) ? $payload['status'] : [];
        $metrics = is_array($payload['metrics'] ?? null) ? $payload['metrics'] : [];
        $alive = is_array($payload['alive'] ?? null) ? $payload['alive'] : [];

        $trafficEntries = $this->parseTrafficEntries($payload['traffic'] ?? []);
        $trafficUpload = array_sum(array_column($trafficEntries, 'upload'));
        $trafficDownload = array_sum(array_column($trafficEntries, 'download'));

        $cpuUsage = (float) ($status['cpu'] ?? 0);
        $memTotal = (float) ($status['mem']['total'] ?? 0);
        $memUsed = (float) ($status['mem']['used'] ?? 0);
        $diskTotal = (float) ($status['disk']['total'] ?? 0);
        $diskUsed = (float) ($status['disk']['used'] ?? 0);
        $memUsage = $memTotal > 0 ? ($memUsed * 100 / $memTotal) : 0.0;
        $diskUsage = $diskTotal > 0 ? ($diskUsed * 100 / $diskTotal) : 0.0;

        $inboundSpeed = (float) ($status['inbound_speed'] ?? $metrics['inbound_speed'] ?? 0);
        $outboundSpeed = (float) ($status['outbound_speed'] ?? $metrics['outbound_speed'] ?? 0);
        $tcpConnections = (float) ($metrics['tcp_connections'] ?? 0);
        $aliveUsers = is_numeric($metrics['active_users'] ?? null)
            ? (float) $metrics['active_users']
            : (float) $this->countAliveUsers($alive);

        return [
            'date' => $time->toDateString(),
            'hour' => (int) $time->hour,
            'node_id' => $nodeId,
            'node_type' => (string) ($payload['node_type'] ?? $payload['nodeType'] ?? self::DEFAULT_NODE_TYPE),
            'traffic_entries' => $trafficEntries,
            'traffic_upload' => (int) $trafficUpload,
            'traffic_download' => (int) $trafficDownload,
            'cpu_usage' => $cpuUsage,
            'mem_usage' => $memUsage,
            'disk_usage' => $diskUsage,
            'inbound_speed' => $inboundSpeed,
            'outbound_speed' => $outboundSpeed,
            'tcp_connections' => $tcpConnections,
            'alive_users' => $aliveUsers,
        ];
    }

    private function parseTrafficEntries($traffic): array
    {
        if (!is_array($traffic) || empty($traffic)) {
            return [];
        }

        $entries = [];
        $isAssoc = array_keys($traffic) !== range(0, count($traffic) - 1);

        if ($isAssoc) {
            foreach ($traffic as $uid => $value) {
                if (!is_numeric($uid)) {
                    continue;
                }

                $userId = (int) $uid;
                if ($userId <= 0) {
                    continue;
                }

                if (is_array($value) && isset($value[0], $value[1]) && is_numeric($value[0]) && is_numeric($value[1])) {
                    $entries[] = [
                        'user_id' => $userId,
                        'upload' => (int) $value[0],
                        'download' => (int) $value[1],
                    ];
                    continue;
                }

                if (is_numeric($value)) {
                    $entries[] = [
                        'user_id' => $userId,
                        'upload' => 0,
                        'download' => (int) $value,
                    ];
                }
            }

            return $entries;
        }

        foreach ($traffic as $row) {
            if (!is_array($row) || count($row) < 2) {
                continue;
            }

            if (!isset($row[0], $row[1]) || !is_numeric($row[0]) || !is_numeric($row[1])) {
                continue;
            }

            $userId = (int) $row[0];
            if ($userId <= 0) {
                continue;
            }

            $entries[] = [
                'user_id' => $userId,
                'upload' => 0,
                'download' => (int) $row[1],
            ];
        }

        return $entries;
    }

    private function countAliveUsers(array $alive): int
    {
        if (empty($alive)) {
            return 0;
        }

        $isAssoc = array_keys($alive) !== range(0, count($alive) - 1);
        if ($isAssoc) {
            $count = 0;
            foreach ($alive as $uid => $value) {
                if (is_numeric($uid) && (int) $uid > 0) {
                    $count++;
                }
            }
            return $count;
        }

        return count($alive);
    }

    private function loadNodeMeta(array $nodeIds): array
    {
        if (empty($nodeIds)) {
            return [];
        }

        $rows = DB::table('v2_server as s')
            ->leftJoin('machines as m', 'm.id', '=', 's.machine_id')
            ->leftJoin('ip_machine as im', function ($join) {
                $join->on('im.machine_id', '=', 'm.id')
                    ->where('im.bind_status', '=', 'active')
                    ->where('im.is_primary', '=', 1);
            })
            ->leftJoin('v2_ip_pool as ip', 'ip.id', '=', 'im.ip_id')
            ->whereIn('s.id', $nodeIds)
            ->groupBy('s.id', 's.type', 's.host')
            ->selectRaw('s.id as node_id, s.type as node_type, s.host as node_host')
            ->selectRaw("COALESCE(MAX(ip.ip), MAX(m.ip_address), '') as node_public_ip")
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->node_id] = [
                'node_type' => (string) ($row->node_type ?? self::DEFAULT_NODE_TYPE),
                'node_host' => (string) ($row->node_host ?? self::DEFAULT_NODE_HOST),
                'node_public_ip' => (string) ($row->node_public_ip ?? self::DEFAULT_NODE_PUBLIC_IP),
            ];
        }

        return $map;
    }

    private function loadUserMeta(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = User::query()
            ->select(['id', 'register_metadata'])
            ->whereIn('id', $userIds)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $meta = is_array($row->register_metadata) ? $row->register_metadata : [];
            $map[(int) $row->id] = [
                'app_id' => (string) ($meta['app_id'] ?? $meta['appId'] ?? ''),
                'app_version' => (string) ($meta['app_version'] ?? $meta['appVersion'] ?? ''),
                'country' => (string) ($meta['country'] ?? $meta['client_country'] ?? ''),
            ];
        }

        return $map;
    }

    private function normalizeTimestampMs($timestamp): ?int
    {
        if ($timestamp === null || $timestamp === '' || !is_numeric($timestamp)) {
            return null;
        }

        $value = (int) $timestamp;
        if ($value <= 0) {
            return null;
        }

        return $value < 1000000000000 ? $value * 1000 : $value;
    }

    private function mergeWeightedAverage(float $oldAvg, int $oldCount, float $newSum, int $newCount): float
    {
        $totalCount = $oldCount + $newCount;
        if ($totalCount <= 0) {
            return 0.0;
        }

        return round((($oldAvg * $oldCount) + $newSum) / $totalCount, 6);
    }
}
