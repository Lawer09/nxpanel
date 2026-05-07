<?php

namespace App\Console\Commands;

use App\Models\NodePerformanceAggregated;
use App\Models\Server;
use App\Services\NodePerformanceService;
use App\Services\OssArchiveService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 每 5 分钟从 Redis 弹出原始上报数据，聚合写入 DB，原始数据归档到 OSS
 *
 * 用法：
 *   php artisan perf:aggregate
 */
class AggregatePerformanceReports extends Command
{
    protected $signature = 'perf:aggregate
        {--batch=5000 : 每次从 Redis 弹出的最大条数}
        {--temp : 同时写入 *_temp 调试表}';

    protected $description = '聚合 Redis 中的节点性能上报数据（5 分钟粒度），原始数据归档到 OSS';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $writeTemp = (bool) $this->option('temp');

        // 1. 计算前 5 分钟桶的 key
        $now = Carbon::now();
        $prevBucket = $now->copy()->subMinutes(5);
        $bucketKey = NodePerformanceService::bucketKeyAt($prevBucket);

        // 2. 从 Redis 弹出该桶全部数据
        $rawPayloads = NodePerformanceService::popBucket($bucketKey, $batchSize);

        if (empty($rawPayloads)) {
            $this->info("No raw reports in bucket: {$bucketKey}");
            return self::SUCCESS;
        }

        $this->info("Popped " . count($rawPayloads) . " raw payloads from bucket: {$bucketKey}");

        // 2.1 归档原始 payload 到 OSS（保持原始结构，便于后续审计回放）
        $this->archiveRawPayloadsToOss($rawPayloads, $bucketKey);

        // 3. 解析 payload 为扁平记录（兼容旧格式）
        $rawRecords = $this->flattenPayloads($rawPayloads);

        if (empty($rawRecords)) {
            $this->warn("No valid report rows found in payloads: {$bucketKey}");
            return self::SUCCESS;
        }

        // 4. 归档原始数据到 OSS
        $this->archiveToOss($rawRecords);
        $internalNodeRecords = array_values(array_filter($rawRecords, fn($record) => (int) ($record['node_id'] ?? 0) > 0));
        $probeNodeRecords = array_values(array_filter($rawRecords, function ($record) {
            $nodeId = (int) ($record['node_id'] ?? 0);
            $nodeIp = trim((string) ($record['node_ip'] ?? ''));
            return $nodeId > 0 || $nodeIp !== '';
        }));

        $grouped = collect($internalNodeRecords)->groupBy(function ($record) {
            $bucket = $this->resolveEventBucket($record);
            return implode('|', [
                $bucket['date'],
                $bucket['hour'],
                $bucket['minute'],
                $record['node_id'] ?? 0,
                $record['client_country'] ?? '',
                $record['platform'] ?? '',
                $record['client_isp'] ?? '',
                $record['app_id'] ?? '',
                $record['app_version'] ?? '',
            ]);
        });

        $upsertData = [];

        foreach ($grouped as $key => $items) {
            $first = $items->first();

            $avgDelay = $items->avg('delay');
            $avgSuccessRate = $items->avg('success_rate');
            $totalCount = $items->count();
            $bucket = $this->resolveEventBucket($first);

            $upsertData[] = [
                'date'             => $bucket['date'],
                'hour'             => $bucket['hour'],
                'minute'           => $bucket['minute'],
                'node_id'          => (int) ($first['node_id'] ?? 0),
                'client_country'   => $first['client_country'] ?? null,
                'platform'         => $first['platform'] ?? null,
                'client_isp'       => $first['client_isp'] ?? null,
                'app_id'           => $first['app_id'] ?? null,
                'app_version'      => $first['app_version'] ?? null,
                'avg_success_rate' => round($avgSuccessRate, 2),
                'avg_delay'        => round($avgDelay, 2),
                'total_count'      => $totalCount,
            ];
        }

        // 5.1 探测状态/错误码聚合（用于节点错误排查）
        $this->aggregateProbeMetrics($probeNodeRecords, $writeTemp);

        // 5.2 节点流量聚合（用于客户端上报流量分析，按 arise_timestamp 归桶）
        // 注意：这里必须使用 rawRecords，不能使用 probeNodeRecords。
        // probeNodeRecords 会过滤掉 node_id=0 且 node_ip 为空的数据，导致客户端流量无法入表。
        $this->aggregateTrafficMetrics($rawRecords, $writeTemp);

        // 5. 写入 DB（upsert：同维度累加）
        foreach ($upsertData as $row) {
            DB::table('v2_node_performance_aggregated')->updateOrInsert(
                [
                    'date'           => $row['date'],
                    'hour'           => $row['hour'],
                    'minute'         => $row['minute'],
                    'node_id'        => $row['node_id'],
                    'client_country' => $row['client_country'],
                    'platform'       => $row['platform'],
                    'client_isp'     => $row['client_isp'],
                    'app_id'         => $row['app_id'],
                    'app_version'    => $row['app_version'],
                ],
                [
                    // 增量合并：加权平均
                    'avg_success_rate' => DB::raw(sprintf(
                        'ROUND((avg_success_rate * total_count + %s * %d) / (total_count + %d), 2)',
                        $row['avg_success_rate'],
                        $row['total_count'],
                        $row['total_count']
                    )),
                    'avg_delay' => DB::raw(sprintf(
                        'ROUND((avg_delay * total_count + %s * %d) / (total_count + %d), 2)',
                        $row['avg_delay'],
                        $row['total_count'],
                        $row['total_count']
                    )),
                    'total_count' => DB::raw('total_count + ' . $row['total_count']),
                ]
            );

            if ($writeTemp) {
                DB::table('v2_node_performance_aggregated_temp')->updateOrInsert(
                    [
                        'date'           => $row['date'],
                        'hour'           => $row['hour'],
                        'minute'         => $row['minute'],
                        'node_id'        => $row['node_id'],
                        'client_country' => $row['client_country'],
                        'platform'       => $row['platform'],
                        'client_isp'     => $row['client_isp'],
                        'app_id'         => $row['app_id'],
                        'app_version'    => $row['app_version'],
                    ],
                    [
                        'avg_success_rate' => DB::raw(sprintf(
                            'ROUND((avg_success_rate * total_count + %s * %d) / (total_count + %d), 2)',
                            $row['avg_success_rate'],
                            $row['total_count'],
                            $row['total_count']
                        )),
                        'avg_delay' => DB::raw(sprintf(
                            'ROUND((avg_delay * total_count + %s * %d) / (total_count + %d), 2)',
                            $row['avg_delay'],
                            $row['total_count'],
                            $row['total_count']
                        )),
                        'total_count' => DB::raw('total_count + ' . $row['total_count']),
                    ]
                );
            }
        }

        $this->info("Aggregated " . count($upsertData) . " dimension groups into DB.");

        // 6. 用户上报次数统计（按 payload 次数统计；一个 batchReport 记 1 次）
        $this->aggregateUserReportCount($rawPayloads, $writeTemp);

        // 7. 清理旧数据
        $this->pruneOldData();

        Log::info('perf:aggregate completed', [
            'bucket'          => $bucketKey,
            'raw_count'       => count($rawRecords),
            'dimension_count' => count($upsertData),
        ]);

        return self::SUCCESS;
    }

    /**
     * 将 Redis 中的 payload 展平为可聚合记录
     */
    private function flattenPayloads(array $payloads): array
    {
        $flattened = [];

        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            $metadataTimestampMs = $this->resolveMetadataTimestampMs($metadata);
            $vpnDataList = $this->extractVpnConnectData($payload);
            $primaryVpnData = $vpnDataList[0] ?? [];
            $vpnData = $primaryVpnData;
            $vpnStatus = $this->normalizeStatus($vpnData['vpn_status'] ?? null);
            $vpnProbeStage = $this->normalizeProbeStage($vpnData['vpn_type'] ?? null);
            $vpnErrorCode = $this->normalizeErrorCode($vpnData['prohibition_connection'] ?? ($vpnData['vpn_error_msg'] ?? null));
            $vpnNodeIp = $this->normalizeNodeIp($vpnData['vpn_node_ip'] ?? ($vpnData['vpn_node_address'] ?? null));
            $vpnUserTimeSeconds = $this->parseUsageSeconds($vpnData['vpn_user_time'] ?? null);
            $vpnUserTrafficMb = $this->parseUsageMb($vpnData['vpn_user_traffic'] ?? null);
            $vpnAriseTimestampMs = $this->normalizeTimestampMs($vpnData['arise_timestamp'] ?? null);

            // 兼容旧格式：单条记录直接入列
            if (array_key_exists('node_id', $payload)) {
                $payload['metadata_timestamp_ms'] = $metadataTimestampMs;
                $payload['event_timestamp_ms'] = $metadataTimestampMs;
                $flattened[] = $payload;
                continue;
            }

            $reports = is_array($payload['reports'] ?? null) ? $payload['reports'] : [];
            $userId = (int) ($payload['userId'] ?? $payload['user_id'] ?? ($primaryVpnData['my_user_id'] ?? 0));
            $clientIp = $payload['clientIp'] ?? $payload['client_ip'] ?? null;
            $eventTimestampMs = $metadataTimestampMs;
            $reportedAt = $payload['reported_at'] ?? $eventTimestampMs;
            $createdAt = $payload['created_at'] ?? now()->toDateTimeString();

            // reports 为空时也保留一条，用于用户上报次数统计
            if (empty($reports)) {
                if (!empty($vpnDataList)) {
                    foreach ($vpnDataList as $vpnDataItem) {
                        $vpnStatusItem = $this->normalizeStatus($vpnDataItem['vpn_status'] ?? null);
                        $vpnProbeStageItem = $this->normalizeProbeStage($vpnDataItem['vpn_type'] ?? null);
                        $vpnErrorCodeItem = $this->normalizeErrorCode($vpnDataItem['prohibition_connection'] ?? ($vpnDataItem['vpn_error_msg'] ?? null));
                        $vpnNodeIpItem = $this->normalizeNodeIp($vpnDataItem['vpn_node_ip'] ?? ($vpnDataItem['vpn_node_address'] ?? null));
                        $vpnUserTimeSecondsItem = $this->parseUsageSeconds($vpnDataItem['vpn_user_time'] ?? null);
                        $vpnUserTrafficMbItem = $this->parseUsageMb($vpnDataItem['vpn_user_traffic'] ?? null);
                        $vpnAriseTimestampMsItem = $this->normalizeTimestampMs($vpnDataItem['arise_timestamp'] ?? null);

                        $emptyNodeId = 0;
                        if ($vpnNodeIpItem) {
                            $emptyNodeId = $this->resolveNodeIdByNodeIp($vpnNodeIpItem);
                        }

                        $flattened[] = [
                            'user_id' => $userId,
                            'node_id' => $emptyNodeId,
                            'node_ip' => $vpnNodeIpItem,
                            'delay' => 0,
                            'success_rate' => 0,
                            'client_ip' => $clientIp,
                            'client_country' => $metadata['country'] ?? null,
                            'client_city' => $metadata['city'] ?? null,
                            'client_isp' => $metadata['isp'] ?? null,
                            'platform' => $metadata['platform'] ?? null,
                            'brand' => $metadata['brand'] ?? null,
                            'app_id' => $metadata['app_id'] ?? null,
                            'app_version' => $metadata['app_version'] ?? null,
                            'connect_country' => $metadata['connect_country'] ?? null,
                            'status' => $vpnStatusItem,
                            'probe_stage' => $vpnProbeStageItem,
                            'error_code' => $vpnErrorCodeItem,
                            'vpn_user_time_seconds' => $vpnUserTimeSecondsItem,
                            'vpn_user_traffic_mb' => $vpnUserTrafficMbItem,
                            'vpn_status' => $vpnDataItem['vpn_status'] ?? null,
                            'prohibition_connection' => $vpnDataItem['prohibition_connection'] ?? null,
                            'vpn_type' => $vpnDataItem['vpn_type'] ?? null,
                            'vpn_error_msg' => $vpnDataItem['vpn_error_msg'] ?? null,
                            'vpn_node_address' => $vpnDataItem['vpn_node_address'] ?? null,
                            'vpn_source' => $vpnDataItem['vpn_source'] ?? null,
                            'my_user_id' => isset($vpnDataItem['my_user_id']) ? (int) $vpnDataItem['my_user_id'] : null,
                            'metadata_timestamp_ms' => $metadataTimestampMs,
                            'event_timestamp_ms' => $eventTimestampMs,
                            'arise_timestamp_ms' => $vpnAriseTimestampMsItem,
                            'reported_at' => $reportedAt,
                            'created_at' => $createdAt,
                        ];
                    }

                    continue;
                }

                $emptyNodeId = 0;
                if ($vpnNodeIp) {
                    $emptyNodeId = $this->resolveNodeIdByNodeIp($vpnNodeIp);
                }

                $flattened[] = [
                    'user_id' => $userId,
                    'node_id' => $emptyNodeId,
                    'node_ip' => $vpnNodeIp,
                    'delay' => 0,
                    'success_rate' => 0,
                    'client_ip' => $clientIp,
                    'client_country' => $metadata['country'] ?? null,
                    'client_city' => $metadata['city'] ?? null,
                    'client_isp' => $metadata['isp'] ?? null,
                    'platform' => $metadata['platform'] ?? null,
                    'brand' => $metadata['brand'] ?? null,
                    'app_id' => $metadata['app_id'] ?? null,
                    'app_version' => $metadata['app_version'] ?? null,
                    'connect_country' => $metadata['connect_country'] ?? null,
                    'status' => $vpnStatus,
                    'probe_stage' => $vpnProbeStage,
                    'error_code' => $vpnErrorCode,
                    'vpn_user_time_seconds' => $vpnUserTimeSeconds,
                    'vpn_user_traffic_mb' => $vpnUserTrafficMb,
                    'vpn_status' => $vpnData['vpn_status'] ?? null,
                    'prohibition_connection' => $vpnData['prohibition_connection'] ?? null,
                    'vpn_type' => $vpnData['vpn_type'] ?? null,
                    'vpn_error_msg' => $vpnData['vpn_error_msg'] ?? null,
                    'vpn_node_address' => $vpnData['vpn_node_address'] ?? null,
                    'vpn_source' => $vpnData['vpn_source'] ?? null,
                    'my_user_id' => isset($vpnData['my_user_id']) ? (int) $vpnData['my_user_id'] : null,
                    'metadata_timestamp_ms' => $metadataTimestampMs,
                    'event_timestamp_ms' => $eventTimestampMs,
                    'arise_timestamp_ms' => $vpnAriseTimestampMs,
                    'reported_at' => $reportedAt,
                    'created_at' => $createdAt,
                ];
                continue;
            }

            foreach ($reports as $report) {
                if (!is_array($report)) {
                    continue;
                }

                $status = $vpnStatus ?? $this->normalizeStatus($report['status'] ?? null);
                $probeStage = $vpnProbeStage ?? $this->normalizeProbeStage($report['probe_stage'] ?? null);
                $errorCode = $vpnErrorCode ?? $this->normalizeErrorCode($report['error_code'] ?? null);
                $nodeIp = $vpnNodeIp ?? $this->normalizeNodeIp($report['node_ip'] ?? ($report['vpn_node_ip'] ?? null));
                $nodeId = (int) ($report['node_id'] ?? 0);
                $vpnUserTimeSecondsCurrent = $vpnUserTimeSeconds > 0
                    ? $vpnUserTimeSeconds
                    : $this->parseUsageSeconds($report['vpn_user_time'] ?? null);
                $vpnUserTrafficMbCurrent = $vpnUserTrafficMb > 0
                    ? $vpnUserTrafficMb
                    : $this->parseUsageMb($report['vpn_user_traffic'] ?? null);
                $ariseTimestampMs = $vpnAriseTimestampMs ?? $this->normalizeTimestampMs($report['arise_timestamp'] ?? null);

                // 外部节点上报只有 IP/域名 时，尝试映射到内部 node_id（缓存 + DB）
                if ($nodeId <= 0 && $nodeIp) {
                    $nodeId = $this->resolveNodeIdByNodeIp($nodeIp);
                }

                // 兼容客户端：未上报 status 但带 error_code 时，按失败处理
                if (!$status && $errorCode) {
                    $status = 'failed';
                }

                if ($status === 'success') {
                    $errorCode = null;
                }

                $flattened[] = [
                    'user_id' => $userId,
                    'node_id' => $nodeId,
                    'node_ip' => $nodeIp,
                    'delay' => (int) ($report['delay'] ?? 0) < 0 ? 0 : (int) ($report['delay'] ?? 0),
                    'success_rate' => (int) ($report['success_rate'] ?? 0),
                    'client_ip' => $clientIp,
                    'client_country' => $metadata['country'] ?? null,
                    'client_city' => $metadata['city'] ?? null,
                    'client_isp' => $metadata['isp'] ?? null,
                    'platform' => $metadata['platform'] ?? null,
                    'brand' => $metadata['brand'] ?? null,
                    'app_id' => $metadata['app_id'] ?? null,
                    'app_version' => $metadata['app_version'] ?? null,
                    'connect_country' => $metadata['connect_country'] ?? null,
                    'status' => $status,
                    'probe_stage' => $probeStage,
                    'error_code' => $errorCode,
                    'vpn_user_time_seconds' => $vpnUserTimeSecondsCurrent,
                    'vpn_user_traffic_mb' => $vpnUserTrafficMbCurrent,
                    'vpn_status' => $vpnData['vpn_status'] ?? null,
                    'prohibition_connection' => $vpnData['prohibition_connection'] ?? null,
                    'vpn_type' => $vpnData['vpn_type'] ?? null,
                    'vpn_error_msg' => $vpnData['vpn_error_msg'] ?? null,
                    'vpn_node_address' => $vpnData['vpn_node_address'] ?? null,
                    'vpn_source' => $vpnData['vpn_source'] ?? null,
                    'my_user_id' => isset($vpnData['my_user_id']) ? (int) $vpnData['my_user_id'] : null,
                    'metadata_timestamp_ms' => $metadataTimestampMs,
                    'event_timestamp_ms' => $eventTimestampMs,
                    'arise_timestamp_ms' => $ariseTimestampMs,
                    'reported_at' => $reportedAt,
                    'created_at' => $createdAt,
                ];
            }
        }

        return $flattened;
    }

    /**
     * 探测数据聚合：按节点 + 环境 + 阶段 + 状态 + 错误码汇总
     */
    private function aggregateProbeMetrics(array $nodeRecords, bool $writeTemp = false): void
    {
        $probeRecords = array_values(array_filter($nodeRecords, function ($record) {
            return !empty($record['status']) || !empty($record['error_code']) || !empty($record['probe_stage']);
        }));

        if (empty($probeRecords)) {
            return;
        }

        $grouped = collect($probeRecords)->groupBy(function ($record) {
            return implode('|', [
                $record['node_id'] ?? 0,
                $record['node_ip'] ?? '',
                $record['client_country'] ?? '',
                $record['platform'] ?? '',
                $record['client_isp'] ?? '',
                $record['app_id'] ?? '',
                $record['app_version'] ?? '',
                $record['probe_stage'] ?? 'unknown',
                $record['status'] ?? 'unknown',
                $record['error_code'] ?? '',
            ]);
        });

        foreach ($grouped as $items) {
            $first = $items->first();
            $bucket = $this->resolveEventBucket($first);
            $dimensionHash = md5(implode('|', [
                $bucket['date'],
                $bucket['hour'],
                $bucket['minute'],
                (int) ($first['node_id'] ?? 0),
                (string) ($first['node_ip'] ?? ''),
                (string) ($first['client_country'] ?? ''),
                (string) ($first['platform'] ?? ''),
                (string) ($first['client_isp'] ?? ''),
                (string) ($first['app_id'] ?? ''),
                (string) ($first['app_version'] ?? ''),
                (string) ($first['probe_stage'] ?? 'unknown'),
                (string) ($first['status'] ?? 'unknown'),
                (string) ($first['error_code'] ?? ''),
            ]));

            DB::table('v2_node_probe_aggregated')->updateOrInsert(
                [
                    'dimension_hash' => $dimensionHash,
                ],
                [
                    'date' => $bucket['date'],
                    'hour' => $bucket['hour'],
                    'minute' => $bucket['minute'],
                    'node_id' => (int) ($first['node_id'] ?? 0),
                    'node_ip' => $first['node_ip'] ?? null,
                    'client_country' => $first['client_country'] ?? null,
                    'platform' => $first['platform'] ?? null,
                    'client_isp' => $first['client_isp'] ?? null,
                    'app_id' => $first['app_id'] ?? null,
                    'app_version' => $first['app_version'] ?? null,
                    'probe_stage' => $first['probe_stage'] ?? 'unknown',
                    'status' => $first['status'] ?? 'unknown',
                    'error_code' => $first['error_code'] ?? null,
                    'total_count' => DB::raw('total_count + ' . $items->count()),
                ]
            );

            if ($writeTemp) {
                DB::table('v2_node_probe_aggregated_temp')->updateOrInsert(
                    [
                        'dimension_hash' => $dimensionHash,
                    ],
                    [
                        'date' => $bucket['date'],
                        'hour' => $bucket['hour'],
                        'minute' => $bucket['minute'],
                        'node_id' => (int) ($first['node_id'] ?? 0),
                        'node_ip' => $first['node_ip'] ?? null,
                        'client_country' => $first['client_country'] ?? null,
                        'platform' => $first['platform'] ?? null,
                        'client_isp' => $first['client_isp'] ?? null,
                        'app_id' => $first['app_id'] ?? null,
                        'app_version' => $first['app_version'] ?? null,
                        'probe_stage' => $first['probe_stage'] ?? 'unknown',
                        'status' => $first['status'] ?? 'unknown',
                        'error_code' => $first['error_code'] ?? null,
                        'total_count' => DB::raw('total_count + ' . $items->count()),
                    ]
                );
            }
        }

        $this->info('Aggregated probe metrics groups: ' . $grouped->count());
    }

    /**
     * 节点流量聚合：按节点 + 环境 + 时间桶汇总使用时长与流量
     * 时间桶取 arise_timestamp（用户结束使用时间），若缺失则回退 reported_at
     */
    private function aggregateTrafficMetrics(array $nodeRecords, bool $writeTemp = false): void
    {
        $trafficRecords = array_values(array_filter($nodeRecords, function ($record) {
            $seconds = (int) ($record['vpn_user_time_seconds'] ?? 0);
            $mb = (float) ($record['vpn_user_traffic_mb'] ?? 0);
            return $seconds > 0 || $mb > 0;
        }));

        if (empty($trafficRecords)) {
            return;
        }

        $grouped = collect($trafficRecords)->groupBy(function ($record) {
            $bucket = $this->resolveTrafficBucket($record);

            return implode('|', [
                $bucket['date'],
                $bucket['hour'],
                $bucket['minute'],
                $record['user_id'] ?? 0,
                $record['node_id'] ?? 0,
                $record['node_ip'] ?? '',
                $record['client_country'] ?? '',
                $record['platform'] ?? '',
                $record['client_isp'] ?? '',
                $record['app_id'] ?? '',
                $record['app_version'] ?? '',
            ]);
        });

        foreach ($grouped as $items) {
            $first = $items->first();
            $bucket = $this->resolveTrafficBucket($first);
            $totalSeconds = (int) $items->sum('vpn_user_time_seconds');
            $totalMb = round((float) $items->sum('vpn_user_traffic_mb'), 3);
            $reportCount = $items->count();
            $dimensionHash = md5(implode('|', [
                $bucket['date'],
                $bucket['hour'],
                $bucket['minute'],
                (int) ($first['user_id'] ?? 0),
                (int) ($first['node_id'] ?? 0),
                (string) ($first['node_ip'] ?? ''),
                (string) ($first['client_country'] ?? ''),
                (string) ($first['platform'] ?? ''),
                (string) ($first['client_isp'] ?? ''),
                (string) ($first['app_id'] ?? ''),
                (string) ($first['app_version'] ?? ''),
            ]));

            DB::table('v2_node_traffic_aggregated')->updateOrInsert(
                [
                    'dimension_hash' => $dimensionHash,
                ],
                [
                    'date' => $bucket['date'],
                    'hour' => $bucket['hour'],
                    'minute' => $bucket['minute'],
                    'user_id' => (int) ($first['user_id'] ?? 0),
                    'node_id' => (int) ($first['node_id'] ?? 0),
                    'node_ip' => $first['node_ip'] ?? null,
                    'client_country' => $first['client_country'] ?? null,
                    'platform' => $first['platform'] ?? null,
                    'client_isp' => $first['client_isp'] ?? null,
                    'app_id' => $first['app_id'] ?? null,
                    'app_version' => $first['app_version'] ?? null,
                    'total_usage_seconds' => DB::raw('total_usage_seconds + ' . $totalSeconds),
                    'total_usage_mb' => DB::raw('ROUND(total_usage_mb + ' . $totalMb . ', 3)'),
                    'report_count' => DB::raw('report_count + ' . $reportCount),
                ]
            );

            if ($writeTemp) {
                DB::table('v2_node_traffic_aggregated_temp')->updateOrInsert(
                    [
                        'dimension_hash' => $dimensionHash,
                    ],
                    [
                        'date' => $bucket['date'],
                        'hour' => $bucket['hour'],
                        'minute' => $bucket['minute'],
                        'user_id' => (int) ($first['user_id'] ?? 0),
                        'node_id' => (int) ($first['node_id'] ?? 0),
                        'node_ip' => $first['node_ip'] ?? null,
                        'client_country' => $first['client_country'] ?? null,
                        'platform' => $first['platform'] ?? null,
                        'client_isp' => $first['client_isp'] ?? null,
                        'app_id' => $first['app_id'] ?? null,
                        'app_version' => $first['app_version'] ?? null,
                        'total_usage_seconds' => DB::raw('total_usage_seconds + ' . $totalSeconds),
                        'total_usage_mb' => DB::raw('ROUND(total_usage_mb + ' . $totalMb . ', 3)'),
                        'report_count' => DB::raw('report_count + ' . $reportCount),
                    ]
                );
            }
        }

        $this->info('Aggregated traffic metrics groups: ' . $grouped->count());
    }

    /**
     * 按用户聚合上报次数，写入 v3_user_report_count
     * 统计口径：每个 payload（即一次 batchReport）计 1 次，不按 reports 条数累计
     */
    private function aggregateUserReportCount(array $rawPayloads, bool $writeTemp = false): void
    {
        $payloadRows = [];

        foreach ($rawPayloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            $reports = is_array($payload['reports'] ?? null) ? $payload['reports'] : [];
            $userId = (int) ($payload['userId'] ?? $payload['user_id'] ?? 0);

            if ($userId <= 0) {
                continue;
            }

            $bucket = $this->resolvePayloadBucket($payload, $metadata);

            // 兼容旧格式：单条记录直传 node_id
            if (array_key_exists('node_id', $payload)) {
                $nodeCount = (int) ($payload['node_id'] ?? 0) > 0 ? 1 : 0;
            } else {
                $nodeCount = collect($reports)
                    ->filter(fn($row) => is_array($row))
                    ->pluck('node_id')
                    ->map(fn($id) => (int) $id)
                    ->filter(fn($id) => $id > 0)
                    ->unique()
                    ->count();
            }

            $payloadRows[] = [
                'user_id' => $userId,
                'node_count' => $nodeCount,
                'client_country' => $metadata['country'] ?? ($payload['client_country'] ?? null),
                'client_isp' => $metadata['isp'] ?? ($payload['client_isp'] ?? null),
                'platform' => $metadata['platform'] ?? ($payload['platform'] ?? null),
                'app_id' => $metadata['app_id'] ?? ($payload['app_id'] ?? null),
                'app_version' => $metadata['app_version'] ?? ($payload['app_version'] ?? null),
                'date' => $bucket['date'],
                'hour' => $bucket['hour'],
                'minute' => $bucket['minute'],
            ];
        }

        $userGrouped = collect($payloadRows)->groupBy(function ($row) {
            return implode('|', [
                $row['date'],
                $row['hour'],
                $row['minute'],
                $row['user_id'],
            ]);
        });

        foreach ($userGrouped as $items) {
            $last = $items->last();
            $userId = (int) ($last['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            DB::table('v3_user_report_count')->updateOrInsert(
                [
                    'date'    => $last['date'],
                    'hour'    => (int) $last['hour'],
                    'minute'  => (int) $last['minute'],
                    'user_id' => $userId,
                ],
                [
                    'report_count'   => DB::raw('report_count + ' . $items->count()),
                    'node_count'     => (int) ($items->max('node_count') ?? 0),
                    'client_country' => $last['client_country'] ?? null,
                    'client_isp'     => $last['client_isp'] ?? null,
                    'platform'       => $last['platform'] ?? null,
                    'app_id'         => $last['app_id'] ?? null,
                    'app_version'    => $last['app_version'] ?? null,
                ]
            );

            if ($writeTemp) {
                DB::table('v3_user_report_count_temp')->updateOrInsert(
                    [
                        'date'    => $last['date'],
                        'hour'    => (int) $last['hour'],
                        'minute'  => (int) $last['minute'],
                        'user_id' => $userId,
                    ],
                    [
                        'report_count'   => DB::raw('report_count + ' . $items->count()),
                        'node_count'     => (int) ($items->max('node_count') ?? 0),
                        'client_country' => $last['client_country'] ?? null,
                        'client_isp'     => $last['client_isp'] ?? null,
                        'platform'       => $last['platform'] ?? null,
                        'app_id'         => $last['app_id'] ?? null,
                        'app_version'    => $last['app_version'] ?? null,
                    ]
                );
            }
        }

        $this->info("Aggregated user report counts for " . $userGrouped->count() . " users.");
    }

    /**
     * 删除 30 天前的聚合数据和用户上报统计数据
     */
    private function pruneOldData(): void
    {
        $cutoff = Carbon::now()->subDays(30)->toDateString();

        $deletedAgg = DB::table('v2_node_performance_aggregated')
            ->where('date', '<', $cutoff)
            ->delete();

        $deletedProbe = DB::table('v2_node_probe_aggregated')
            ->where('date', '<', $cutoff)
            ->delete();

        // $deletedTraffic = DB::table('v2_node_traffic_aggregated')
        //     ->where('date', '<', $cutoff)
        //     ->delete();

        // $deletedUser = DB::table('v3_user_report_count')
        //     ->where('date', '<', $cutoff)
        //     ->delete();
    
        $deletedUser = 0; // 暂不删除用户上报统计数据，保留历史查询能力    
        $deletedTraffic = 0; // 暂不删除流量聚合数据，保留历史查询能力
        
        if ($deletedAgg > 0 || $deletedProbe > 0 || $deletedTraffic > 0 || $deletedUser > 0) {
            $this->info("Pruned old data (before {$cutoff}): aggregated={$deletedAgg}, probe={$deletedProbe}, traffic={$deletedTraffic}, user_report={$deletedUser}");
        }
    }

    private function normalizeStatus($status): ?string
    {
        if (!is_string($status)) {
            return null;
        }

        $status = strtolower(trim($status));
        $status = match ($status) {
            'ok', 'connected', 'connect_success', 'successed' => 'success',
            'fail', 'failed_connect', 'connect_failed', 'error', 'disconnected', 'disconnect', 'forbidden' => 'failed',
            'canceled' => 'cancelled',
            default => $status,
        };
        $allowed = ['success', 'failed', 'timeout', 'cancelled'];

        return in_array($status, $allowed, true) ? $status : null;
    }

    private function normalizeProbeStage($stage): ?string
    {
        if (!is_string($stage)) {
            return null;
        }

        $stage = strtolower(trim($stage));

        if ($stage === 'tunnel_establish') {
            return 'node_connect';
        }

        $allowed = ['node_connect', 'post_connect_probe'];
        return in_array($stage, $allowed, true) ? $stage : null;
    }

    private function normalizeErrorCode($errorCode): ?string
    {
        if (!is_string($errorCode)) {
            return null;
        }

        $errorCode = strtolower(trim($errorCode));
        return $errorCode !== '' ? $errorCode : null;
    }

    private function normalizeNodeIp($nodeIp): ?string
    {
        if (!is_string($nodeIp)) {
            return null;
        }

        $nodeIp = trim(strtolower($nodeIp));
        if ($nodeIp === '') {
            return null;
        }

        if (str_starts_with($nodeIp, 'http://') || str_starts_with($nodeIp, 'https://')) {
            $host = parse_url($nodeIp, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $nodeIp = strtolower($host);
            }
        }

        // 兼容 host:port（域名/IP），保留纯 host
        if (preg_match('/^([^:]+):(\d+)$/', $nodeIp, $match) === 1) {
            $nodeIp = $match[1];
        }

        // 兼容 [ipv6]:port
        if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $nodeIp, $match) === 1) {
            $nodeIp = $match[1];
        }

        return $nodeIp !== '' ? $nodeIp : null;
    }

    /**
     * 通过节点 host(IP/域名) 解析 node_id（缓存优先）
     */
    private function resolveNodeIdByNodeIp(string $nodeIp): int
    {
        $cacheKey = 'perf:node_ip_to_id:' . md5($nodeIp);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (int) $cached;
        }

        $server = Server::query()
            ->whereRaw('LOWER(host) = ?', [$nodeIp])
            ->select(['id'])
            ->first();

        $nodeId = (int) ($server->id ?? 0);
        // 命中缓存时间长一些；未命中短缓存，避免新增节点后长时间无法映射
        $ttl = $nodeId > 0 ? now()->addHours(6) : now()->addMinutes(10);
        Cache::put($cacheKey, $nodeId, $ttl);

        return $nodeId;
    }

    private function resolveTrafficBucket(array $record): array
    {
        $timestampMs = $this->resolveTrustedTimestampMs(
            $this->normalizeTimestampMs($record['metadata_timestamp_ms'] ?? null)
        );

        $time = Carbon::createFromTimestampMsUTC($timestampMs)
            ->setTimezone(config('app.timezone', 'Asia/Shanghai'));
        $minute = (int) floor($time->minute / 5) * 5;

        return [
            'date' => $time->toDateString(),
            'hour' => (int) $time->hour,
            'minute' => $minute,
        ];
    }

    private function resolveEventBucket(array $record): array
    {
        $timestampMs = $this->resolveTrustedTimestampMs(
            $this->normalizeTimestampMs($record['metadata_timestamp_ms'] ?? null)
        );

        $time = Carbon::createFromTimestampMsUTC($timestampMs)
            ->setTimezone(config('app.timezone', 'Asia/Shanghai'));
        $minute = (int) floor($time->minute / 5) * 5;

        return [
            'date' => $time->toDateString(),
            'hour' => (int) $time->hour,
            'minute' => $minute,
        ];
    }

    private function resolvePayloadBucket(array $payload, array $metadata): array
    {
        $timestampMs = $this->resolveTrustedTimestampMs(
            $this->normalizeTimestampMs($metadata['timestamp'] ?? null)
        );

        $time = Carbon::createFromTimestampMsUTC($timestampMs)
            ->setTimezone(config('app.timezone', 'Asia/Shanghai'));
        $minute = (int) floor($time->minute / 5) * 5;

        return [
            'date' => $time->toDateString(),
            'hour' => (int) $time->hour,
            'minute' => $minute,
        ];
    }

    private function resolveMetadataTimestampMs(array $metadata): int
    {
        return $this->resolveTrustedTimestampMs(
            $this->normalizeTimestampMs($metadata['timestamp'] ?? null)
        );
    }

    private function resolveTrustedTimestampMs(?int $timestampMs): int
    {
        $nowMs = now()->getTimestampMs();
        if ($timestampMs === null || $timestampMs > $nowMs) {
            return $nowMs;
        }

        return $timestampMs;
    }

    private function normalizeTimestampMs($timestamp): ?int
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        if (is_numeric($timestamp)) {
            $value = (int) $timestamp;
            if ($value <= 0) {
                return null;
            }

            // 兼容秒级时间戳
            return $value < 1000000000000 ? $value * 1000 : $value;
        }

        return null;
    }

    private function extractVpnConnectData(array $payload): array
    {
        $entries = $payload['user_default'] ?? null;
        if (is_string($entries)) {
            $decoded = json_decode($entries, true);
            $entries = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($entries)) {
            return [];
        }

        $rows = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $decoded = json_decode($entry, true);
                $entry = is_array($decoded) ? $decoded : null;
            }

            if (!is_array($entry)) {
                continue;
            }

            $type = strtolower(trim((string) ($entry['type'] ?? '')));
            if (!in_array($type, ['vpn_connect', 'vpn_connection'], true)) {
                continue;
            }

            $data = $entry['data'] ?? null;
            if (is_string($data)) {
                $decoded = json_decode($data, true);
                $data = is_array($decoded) ? $decoded : [];
            }

            if (is_array($data)) {
                $rows[] = $data;
            }
        }

        return $rows;
    }

    private function parseUsageSeconds($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        if (!is_string($value)) {
            return 0;
        }

        $text = trim($value);
        if ($text === '') {
            return 0;
        }

        if (preg_match('/^\d+$/', $text) === 1) {
            return (int) $text;
        }

        $hours = preg_match('/(\d+)\s*(h|hour|hours|时)/iu', $text, $mH) ? (int) $mH[1] : 0;
        $minutes = preg_match('/(\d+)\s*(m|min|minute|minutes|分)/iu', $text, $mM) ? (int) $mM[1] : 0;
        $seconds = preg_match('/(\d+)\s*(s|sec|second|seconds|秒)/iu', $text, $mS) ? (int) $mS[1] : 0;

        $total = $hours * 3600 + $minutes * 60 + $seconds;
        return max(0, $total);
    }

    private function parseUsageMb($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return max(0.0, round((float) $value, 3));
        }

        if (!is_string($value)) {
            return 0.0;
        }

        $text = strtolower(trim($value));
        if ($text === '') {
            return 0.0;
        }

        $text = str_replace(['mb', 'mib', 'gb', 'gib', 'kb', 'kib', 'b', ' ', ','], ['mb', 'mib', 'gb', 'gib', 'kb', 'kib', 'b', '', '.'], $text);
        if (!preg_match('/([0-9]+(?:\.[0-9]+)?)/', $text, $num)) {
            return 0.0;
        }

        $valueNum = (float) $num[1];
        if ($valueNum <= 0) {
            return 0.0;
        }

        if (str_contains($text, 'gib') || str_contains($text, 'gb')) {
            return round($valueNum * 1024, 3);
        }
        if (str_contains($text, 'kib') || str_contains($text, 'kb')) {
            return round($valueNum / 1024, 3);
        }
        if (str_contains($text, 'b') && !str_contains($text, 'mb')) {
            return round($valueNum / 1024 / 1024, 3);
        }

        return round($valueNum, 3);
    }

    /**
     * 将 Redis 弹出的原始 payload 归档到 OSS（NDJSON）
     * 保留最原始结构，便于问题追溯与回放。
     */
    private function archiveRawPayloadsToOss(array $payloads, string $bucketKey): void
    {
        if (empty($payloads)) {
            return;
        }

        if (!OssArchiveService::enabled()) {
            $this->warn('OSS not enabled, skipping raw payload archive.');
            return;
        }

        $now = Carbon::now();
        $safeBucketKey = preg_replace('/[^A-Za-z0-9_\-:]/', '_', $bucketKey) ?: 'unknown_bucket';
        $path = sprintf(
            'perf/raw_payload/%s/%s/%s/%s_%s.ndjson',
            $now->format('Y'),
            $now->format('m'),
            $now->format('d'),
            $safeBucketKey,
            uniqid()
        );

        $ndjson = collect($payloads)
            ->map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE))
            ->implode("\n");

        try {
            $ok = Storage::disk('oss')->put($path, $ndjson);
            if ($ok) {
                $this->info('Archived ' . count($payloads) . " raw payloads to OSS: {$path}");
            } else {
                Log::warning('perf:aggregate raw payload OSS upload failed', ['path' => $path]);
            }
        } catch (\Throwable $e) {
            Log::error('perf:aggregate raw payload OSS upload exception', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 将原始数据归档到 OSS（NDJSON 格式）
     */
    private function archiveToOss(array $records): void
    {
        if (!OssArchiveService::enabled()) {
            $this->warn('OSS not enabled, skipping raw data archive.');
            return;
        }

        $now = Carbon::now();
        $path = sprintf(
            'perf/raw/%s/%s/%s/%s_%s.ndjson',
            $now->format('Y'),
            $now->format('m'),
            $now->format('d'),
            $now->format('H-i-s'),
            uniqid()
        );

        $ndjson = collect($records)
            ->map(fn($r) => json_encode($r, JSON_UNESCAPED_UNICODE))
            ->implode("\n");

        try {
            $ok = Storage::disk('oss')->put($path, $ndjson);
            if ($ok) {
                Log::info("Archived " . count($records) . " raw records to OSS: {$path}");
            } else {
                Log::warning('perf:aggregate OSS upload failed', ['path' => $path]);
            }
        } catch (\Throwable $e) {
            Log::error('perf:aggregate OSS upload exception', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
