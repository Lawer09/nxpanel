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
    protected $signature = 'perf:aggregate {--batch=5000 : 每次从 Redis 弹出的最大条数}';

    protected $description = '聚合 Redis 中的节点性能上报数据（5 分钟粒度），原始数据归档到 OSS';

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');

        // 1. 计算前 5 分钟桶的 key
        $now = Carbon::now();
        $prevBucket = $now->copy()->subMinutes(5);
        $bucketKey = NodePerformanceService::bucketKeyAt($prevBucket);

        // 对齐到该桶的时间窗口
        $minute = (int) floor($prevBucket->minute / 5) * 5;
        $date = $prevBucket->toDateString();
        $hour = $prevBucket->hour;

        // 2. 从 Redis 弹出该桶全部数据
        $rawPayloads = NodePerformanceService::popBucket($bucketKey, $batchSize);

        if (empty($rawPayloads)) {
            $this->info("No raw reports in bucket: {$bucketKey}");
            return self::SUCCESS;
        }

        $this->info("Popped " . count($rawPayloads) . " raw payloads from bucket: {$bucketKey}");

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
            return implode('|', [
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

            $upsertData[] = [
                'date'             => $date,
                'hour'             => $hour,
                'minute'           => $minute,
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
        $this->aggregateProbeMetrics($probeNodeRecords, $date, $hour, $minute);

        // 5.2 节点流量聚合（用于客户端上报流量分析，按 arise_timestamp 归桶）
        $this->aggregateTrafficMetrics($probeNodeRecords);

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
        }

        $this->info("Aggregated " . count($upsertData) . " dimension groups into DB.");

        // 6. 用户上报次数统计（按 payload 次数统计；一个 batchReport 记 1 次）
        $this->aggregateUserReportCount($rawPayloads, $date, $hour, $minute);

        // 7. 清理旧数据
        $this->pruneOldData();

        Log::info('perf:aggregate completed', [
            'bucket'          => $bucketKey,
            'raw_count'       => count($rawRecords),
            'dimension_count' => count($upsertData),
            'date'            => $date,
            'hour'            => $hour,
            'minute'          => $minute,
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

            // 兼容旧格式：单条记录直接入列
            if (array_key_exists('node_id', $payload)) {
                $flattened[] = $payload;
                continue;
            }

            $metadata = $payload['metadata'] ?? [];
            $reports = is_array($payload['reports'] ?? null) ? $payload['reports'] : [];
            $userId = (int) ($payload['userId'] ?? $payload['user_id'] ?? 0);
            $clientIp = $payload['clientIp'] ?? $payload['client_ip'] ?? null;
            $reportedAt = $payload['reported_at'] ?? ($metadata['timestamp'] ?? now()->getTimestampMs());
            $createdAt = $payload['created_at'] ?? now()->toDateTimeString();

            // reports 为空时也保留一条，用于用户上报次数统计
            if (empty($reports)) {
                $flattened[] = [
                    'user_id' => $userId,
                    'node_id' => 0,
                    'node_ip' => null,
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
                    'status' => null,
                    'probe_stage' => null,
                    'error_code' => null,
                    'vpn_user_time_seconds' => 0,
                    'vpn_user_traffic_mb' => 0.0,
                    'arise_timestamp_ms' => null,
                    'reported_at' => $reportedAt,
                    'created_at' => $createdAt,
                ];
                continue;
            }

            foreach ($reports as $report) {
                if (!is_array($report)) {
                    continue;
                }

                $status = $this->normalizeStatus($report['status'] ?? null);
                $probeStage = $this->normalizeProbeStage($report['probe_stage'] ?? null);
                $errorCode = $this->normalizeErrorCode($report['error_code'] ?? null);
                $nodeIp = $this->normalizeNodeIp($report['node_ip'] ?? ($report['vpn_node_ip'] ?? null));
                $nodeId = (int) ($report['node_id'] ?? 0);
                $vpnUserTimeSeconds = $this->parseUsageSeconds($report['vpn_user_time'] ?? null);
                $vpnUserTrafficMb = $this->parseUsageMb($report['vpn_user_traffic'] ?? null);
                $ariseTimestampMs = $this->normalizeTimestampMs($report['arise_timestamp'] ?? null);

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
                    'vpn_user_time_seconds' => $vpnUserTimeSeconds,
                    'vpn_user_traffic_mb' => $vpnUserTrafficMb,
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
    private function aggregateProbeMetrics(array $nodeRecords, string $date, int $hour, int $minute): void
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

            DB::table('v2_node_probe_aggregated')->updateOrInsert(
                [
                    'date' => $date,
                    'hour' => $hour,
                    'minute' => $minute,
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
                ],
                [
                    'total_count' => DB::raw('total_count + ' . $items->count()),
                ]
            );
        }

        $this->info('Aggregated probe metrics groups: ' . $grouped->count());
    }

    /**
     * 节点流量聚合：按节点 + 环境 + 时间桶汇总使用时长与流量
     * 时间桶取 arise_timestamp（用户结束使用时间），若缺失则回退 reported_at
     */
    private function aggregateTrafficMetrics(array $nodeRecords): void
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

            DB::table('v2_node_traffic_aggregated')->updateOrInsert(
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
                ],
                [
                    'total_usage_seconds' => DB::raw('total_usage_seconds + ' . $totalSeconds),
                    'total_usage_mb' => DB::raw('ROUND(total_usage_mb + ' . $totalMb . ', 3)'),
                    'report_count' => DB::raw('report_count + ' . $reportCount),
                ]
            );
        }

        $this->info('Aggregated traffic metrics groups: ' . $grouped->count());
    }

    /**
     * 按用户聚合上报次数，写入 v3_user_report_count
     * 统计口径：每个 payload（即一次 batchReport）计 1 次，不按 reports 条数累计
     */
    private function aggregateUserReportCount(array $rawPayloads, string $date, int $hour, int $minute): void
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
            ];
        }

        $userGrouped = collect($payloadRows)->groupBy('user_id');

        foreach ($userGrouped as $userId => $items) {
            $last = $items->last();

            DB::table('v3_user_report_count')->updateOrInsert(
                [
                    'date'    => $date,
                    'hour'    => $hour,
                    'minute'  => $minute,
                    'user_id' => (int) $userId,
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

        $deletedTraffic = DB::table('v2_node_traffic_aggregated')
            ->where('date', '<', $cutoff)
            ->delete();

        // $deletedUser = DB::table('v3_user_report_count')
        //     ->where('date', '<', $cutoff)
        //     ->delete();
    
        $deletedUser = 0; // 暂不删除用户上报统计数据，保留历史查询能力    
        
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
        $timestampMs = $this->normalizeTimestampMs($record['arise_timestamp_ms'] ?? null)
            ?? $this->normalizeTimestampMs($record['reported_at'] ?? null)
            ?? now()->getTimestampMs();

        $time = Carbon::createFromTimestampMs($timestampMs);
        $minute = (int) floor($time->minute / 5) * 5;

        return [
            'date' => $time->toDateString(),
            'hour' => (int) $time->hour,
            'minute' => $minute,
        ];
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
                $this->info("Archived " . count($records) . " raw records to OSS: {$path}");
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
