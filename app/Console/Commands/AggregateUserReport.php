<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\OssArchiveService;
use App\Services\UserReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AggregateUserReport extends Command
{
    protected $signature = 'user_report:aggregate
        {--batch=10000 : 每次处理的最大 payload 数}
        {--bucket= : 指定桶时间(yyyyMMddHHmm, UTC+8)}';

    protected $description = '聚合用户上报数据（先OSS归档，再统计写库）';

    public function handle(): int
    {
        if (!UserReportService::enabled()) {
            $this->info('USER_REPORT_ENABLED=false, skip.');
            return self::SUCCESS;
        }

        $batch = max(100, (int) $this->option('batch'));
        $bucketTime = $this->resolveTargetBucketTime();
        $bucketKey = UserReportService::bucketKeyAtUtc8($bucketTime);
        $lockKey = 'user_report:agg:lock:' . $bucketTime->format('YmdHi');

        $lock = Cache::lock($lockKey, 240);
        if (!$lock->get()) {
            $this->warn('bucket is locked: ' . $bucketKey);
            return self::SUCCESS;
        }

        try {
            $payloads = UserReportService::readBucket($bucketKey, $batch);
            if (empty($payloads)) {
                $this->info('No payloads in bucket: ' . $bucketKey);
                return self::SUCCESS;
            }

            $archivePath = $this->archiveRawPayloads($payloads, $bucketTime);
            if ($archivePath === null) {
                $this->error('Archive raw payloads failed, keep bucket for retry: ' . $bucketKey);
                return self::FAILURE;
            }

            DB::beginTransaction();
            try {
                $flatRecords = $this->flattenPayloads($payloads);
                $this->aggregateSummary($payloads);
                $this->aggregateNodeSummary($flatRecords);
                $this->aggregateTraffic($flatRecords);
                $this->aggregateNodeFail($flatRecords);
                $this->pruneNodeFail();
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            UserReportService::deleteBucket($bucketKey);

            $this->info(sprintf('aggregate done: bucket=%s payloads=%d archive=%s', $bucketKey, count($payloads), $archivePath));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('user_report:aggregate failed', [
                'bucket_key' => $bucketKey,
                'error' => $e->getMessage(),
            ]);
            $this->error($e->getMessage());
            return self::FAILURE;
        } finally {
            optional($lock)->release();
        }
    }

    private function resolveTargetBucketTime(): Carbon
    {
        $bucket = $this->option('bucket');
        if (is_string($bucket) && preg_match('/^\d{12}$/', $bucket) === 1) {
            return Carbon::createFromFormat('YmdHi', $bucket, 'Asia/Shanghai')->second(0);
        }

        $nowUtc8 = Carbon::now('Asia/Shanghai');
        $alignedMinute = (int) floor(((int) $nowUtc8->minute) / 5) * 5;
        return $nowUtc8->copy()->minute($alignedMinute)->second(0)->subMinutes(5);
    }

    private function archiveRawPayloads(array $payloads, Carbon $bucketTime): ?string
    {
        if (!OssArchiveService::enabled()) {
            $this->warn('OSS not enabled, skip archive and stop aggregation.');
            return null;
        }

        $path = sprintf(
            'user_report/raw/%s/%s/%s/%s_%s.ndjson',
            now()->format('Y'),
            now()->format('m'),
            now()->format('d'),
            now()->format('H-i-s'),
            uniqid()
        );

        $ndjson = collect($payloads)->map(fn($row) => json_encode($row, JSON_UNESCAPED_UNICODE))->implode("\n");

        try {
            $ok = Storage::disk('oss')->put($path, $ndjson);
            if (!$ok) {
                Log::warning('user_report raw archive failed', ['path' => $path]);
                return null;
            }
            Log::info('user_report raw archived', ['path' => $path]);
        } catch (\Throwable $e) {
            Log::error('user_report raw archive exception', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $path;
    }

    private function flattenPayloads(array $payloads): array
    {
        $records = [];

        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            $userId = (int) ($payload['user_id'] ?? 0);
            $reportAtMs = UserReportService::resolveReportAtMs($metadata);
            $time = Carbon::createFromTimestampMsUTC($reportAtMs)->setTimezone('Asia/Shanghai');
            $date = $time->toDateString();
            $hour = (int) $time->hour;
            $appId = (string) ($metadata['app_id'] ?? '');
            $appVersion = (string) ($metadata['app_version'] ?? '');
            $country = (string) ($metadata['country'] ?? '');

            $reports = is_array($payload['reports'] ?? null) ? $payload['reports'] : [];
            foreach ($reports as $report) {
                if (!is_array($report)) {
                    continue;
                }

                $nodeHost = $this->normalizeNodeHost($report['node_host'] ?? ($report['node_ip'] ?? ($report['vpn_node_ip'] ?? null)));
                $nodeId = (int) ($report['node_id'] ?? 0);
                if ($nodeId <= 0 && $nodeHost !== '') {
                    $nodeId = $this->resolveNodeIdByHost($nodeHost);
                }

                $server = $nodeId > 0 ? $this->resolveServerMeta($nodeId) : null;
                $nodeType = $server['type'] ?? '';
                if ($nodeHost === '' && !empty($server['host'])) {
                    $nodeHost = (string) $server['host'];
                }

                $status = strtolower(trim((string) ($report['status'] ?? '')));
                $probeStage = $this->normalizeProbeStage($report['probe_stage'] ?? null);
                $errorCode = trim((string) ($report['error_code'] ?? ''));

                $records[] = [
                    'source' => 'reports',
                    'user_id' => $userId,
                    'app_id' => $appId,
                    'app_version' => $appVersion,
                    'country' => $country,
                    'date' => $date,
                    'hour' => $hour,
                    'node_id' => $nodeId,
                    'node_host' => $nodeHost,
                    'node_type' => $nodeType,
                    'probe_stage' => $probeStage,
                    'delay' => max(0, (int) ($report['delay'] ?? 0)),
                    'traffic_usage' => 0.0,
                    'traffic_use_time' => 0,
                    'status' => $status,
                    'error_code' => $errorCode,
                    'report_at_ms' => $reportAtMs,
                ];
            }

            $vpnDataList = $this->extractVpnConnectionData($payload['user_default'] ?? null);
            foreach ($vpnDataList as $vpnData) {
                $nodeHost = $this->normalizeNodeHost($vpnData['vpn_node_ip'] ?? null);
                $nodeId = $this->resolveNodeIdByHost($nodeHost);
                $server = $nodeId > 0 ? $this->resolveServerMeta($nodeId) : null;
                $nodeType = $server['type'] ?? '';
                if ($nodeHost === '' && !empty($server['host'])) {
                    $nodeHost = (string) $server['host'];
                }

                $vpnStatus = (int) ($vpnData['vpn_status'] ?? 0);
                $delay = $vpnStatus === 2 ? 6000 : 200;
                $trafficUsage = $this->parseUsageMb($vpnData['vpn_user_traffic'] ?? null);
                $trafficUseTime = $this->parseUsageSeconds($vpnData['vpn_user_time'] ?? null);
                $errorCode = trim((string) ($vpnData['vpn_error_msg'] ?? ''));

                $records[] = [
                    'source' => 'vpn_connection',
                    'user_id' => $userId,
                    'app_id' => $appId,
                    'app_version' => $appVersion,
                    'country' => $country,
                    'date' => $date,
                    'hour' => $hour,
                    'node_id' => $nodeId,
                    'node_host' => $nodeHost,
                    'node_type' => $nodeType,
                    'probe_stage' => 'post_connect_probe',
                    'delay' => $delay,
                    'traffic_usage' => $trafficUsage,
                    'traffic_use_time' => $trafficUseTime,
                    'status' => $vpnStatus === 2 ? 'failed' : 'success',
                    'error_code' => $errorCode,
                    'report_at_ms' => $reportAtMs,
                ];
            }
        }

        return $records;
    }

    private function aggregateSummary(array $payloads): void
    {
        $groups = [];

        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            $userId = (int) ($payload['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $reportAtMs = UserReportService::resolveReportAtMs($metadata);
            $time = Carbon::createFromTimestampMsUTC($reportAtMs)->setTimezone('Asia/Shanghai');
            $date = $time->toDateString();
            $hour = (int) $time->hour;
            $appId = (string) ($metadata['app_id'] ?? '');
            $appVersion = (string) ($metadata['app_version'] ?? '');
            $country = (string) ($metadata['country'] ?? '');

            $key = implode('|', [$date, $hour, $userId, $appId, $appVersion, $country]);
            $groups[$key] = ($groups[$key] ?? 0) + 1;
        }

        foreach ($groups as $key => $count) {
            [$date, $hour, $userId, $appId, $appVersion, $country] = explode('|', $key, 6);
            DB::table('v3_user_report_summary')->updateOrInsert(
                [
                    'date' => $date,
                    'hour' => (int) $hour,
                    'user_id' => (int) $userId,
                    'app_id' => $appId,
                    'app_version' => $appVersion,
                    'country' => $country,
                ],
                [
                    'report_count' => DB::raw('report_count + ' . (int) $count),
                    'updated_at' => now(),
                ]
            );
            $this->bumpCacheVersion('v3_user_report_summary', $date, (int) $hour);
        }
    }

    private function aggregateNodeSummary(array $records): void
    {
        $groups = [];

        foreach ($records as $row) {
            $key = implode('|', [
                $row['date'],
                $row['hour'],
                (int) $row['node_id'],
                (string) $row['node_host'],
                (string) $row['node_type'],
                (string) $row['probe_stage'],
            ]);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'date' => $row['date'],
                    'hour' => (int) $row['hour'],
                    'node_id' => (int) $row['node_id'],
                    'node_host' => (string) $row['node_host'],
                    'node_type' => (string) $row['node_type'],
                    'probe_stage' => (string) $row['probe_stage'],
                    'delay_sum' => 0,
                    'traffic_usage' => 0.0,
                    'traffic_use_time' => 0,
                    'compute_count' => 0,
                    'success_count' => 0,
                    'fail_count' => 0,
                ];
            }

            $groups[$key]['delay_sum'] += (int) ($row['delay'] ?? 0);
            $groups[$key]['traffic_usage'] += (float) ($row['traffic_usage'] ?? 0);
            $groups[$key]['traffic_use_time'] += (int) ($row['traffic_use_time'] ?? 0);
            $groups[$key]['compute_count'] += 1;

            $status = strtolower(trim((string) ($row['status'] ?? '')));
            if ($status === 'success') {
                $groups[$key]['success_count'] += 1;
            } elseif ($status === 'failed') {
                $groups[$key]['fail_count'] += 1;
            }
        }

        foreach ($groups as $group) {
            $avgDelay = $group['compute_count'] > 0
                ? round($group['delay_sum'] / $group['compute_count'], 2)
                : 0;

            DB::table('v3_user_report_node_summary')->updateOrInsert(
                [
                    'date' => $group['date'],
                    'hour' => $group['hour'],
                    'node_id' => $group['node_id'],
                    'node_host' => $group['node_host'],
                    'node_type' => $group['node_type'],
                    'probe_stage' => $group['probe_stage'],
                ],
                [
                    'avg_delay' => DB::raw(sprintf(
                        'ROUND((avg_delay * compute_count + %s * %d) / NULLIF(compute_count + %d, 0), 2)',
                        $avgDelay,
                        $group['compute_count'],
                        $group['compute_count']
                    )),
                    'traffic_usage' => DB::raw('traffic_usage + ' . round((float) $group['traffic_usage'], 3)),
                    'traffic_use_time' => DB::raw('traffic_use_time + ' . (int) $group['traffic_use_time']),
                    'compute_count' => DB::raw('compute_count + ' . (int) $group['compute_count']),
                    'success_count' => DB::raw('success_count + ' . (int) $group['success_count']),
                    'fail_count' => DB::raw('fail_count + ' . (int) $group['fail_count']),
                    'updated_at' => now(),
                ]
            );
            $this->bumpCacheVersion('v3_user_report_node_summary', $group['date'], (int) $group['hour']);
        }
    }

    private function aggregateTraffic(array $records): void
    {
        $groups = [];

        foreach ($records as $row) {
            $key = implode('|', [
                $row['date'],
                $row['hour'],
                $row['user_id'],
                $row['app_id'],
                $row['app_version'],
                $row['country'],
            ]);

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'date' => $row['date'],
                    'hour' => (int) $row['hour'],
                    'user_id' => (int) $row['user_id'],
                    'app_id' => (string) $row['app_id'],
                    'app_version' => (string) $row['app_version'],
                    'country' => (string) $row['country'],
                    'traffic_usage' => 0.0,
                    'traffic_use_time' => 0,
                    'compute_count' => 0,
                ];
            }

            $groups[$key]['traffic_usage'] += (float) ($row['traffic_usage'] ?? 0);
            $groups[$key]['traffic_use_time'] += (int) ($row['traffic_use_time'] ?? 0);
            $groups[$key]['compute_count'] += 1;
        }

        foreach ($groups as $group) {
            DB::table('v3_user_report_traffic')->updateOrInsert(
                [
                    'date' => $group['date'],
                    'hour' => $group['hour'],
                    'user_id' => $group['user_id'],
                    'app_id' => $group['app_id'],
                    'app_version' => $group['app_version'],
                    'country' => $group['country'],
                ],
                [
                    'traffic_usage' => DB::raw('traffic_usage + ' . round((float) $group['traffic_usage'], 3)),
                    'traffic_use_time' => DB::raw('traffic_use_time + ' . (int) $group['traffic_use_time']),
                    'compute_count' => DB::raw('compute_count + ' . (int) $group['compute_count']),
                    'updated_at' => now(),
                ]
            );
            $this->bumpCacheVersion('v3_user_report_traffic', $group['date'], (int) $group['hour']);
        }
    }

    private function aggregateNodeFail(array $records): void
    {
        $rows = array_values(array_filter($records, function ($row) {
            $status = strtolower((string) ($row['status'] ?? ''));
            $errorCode = trim((string) ($row['error_code'] ?? ''));
            return $status === 'failed' || $errorCode !== '';
        }));

        if (empty($rows)) {
            return;
        }

        $insertRows = array_map(function ($row) {
            return [
                'date' => $row['date'],
                'hour' => (int) $row['hour'],
                'report_at_ms' => (int) ($row['report_at_ms'] ?? 0),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'app_id' => (string) ($row['app_id'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'node_id' => (int) ($row['node_id'] ?? 0),
                'node_host' => (string) ($row['node_host'] ?? ''),
                'node_type' => (string) ($row['node_type'] ?? ''),
                'probe_stage' => (string) ($row['probe_stage'] ?? ''),
                'error_code' => (string) ($row['error_code'] ?? ''),
                'created_at' => now(),
            ];
        }, $rows);

        DB::table('v3_user_report_node_fail')->insert($insertRows);

        foreach ($rows as $row) {
            $this->bumpCacheVersion('v3_user_report_node_fail', $row['date'], (int) $row['hour']);
        }
    }

    private function pruneNodeFail(): void
    {
        $cutoffDate = Carbon::now('Asia/Shanghai')->subDays(7)->toDateString();
        DB::table('v3_user_report_node_fail')->where('date', '<', $cutoffDate)->delete();
    }

    private function normalizeProbeStage($value): string
    {
        $stage = strtolower(trim((string) $value));
        if ($stage === 'tunnel_establish') {
            return 'node_connect';
        }

        return in_array($stage, ['node_connect', 'post_connect_probe'], true)
            ? $stage
            : 'post_connect_probe';
    }

    private function resolveNodeIdByHost(string $host): int
    {
        if ($host === '') {
            return 0;
        }

        $cacheKey = 'user_report:node_host_to_id:' . md5($host);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (int) $cached;
        }

        $server = Server::query()->whereRaw('LOWER(host)=?', [strtolower($host)])->select(['id'])->first();
        $nodeId = (int) ($server->id ?? 0);
        Cache::put($cacheKey, $nodeId, $nodeId > 0 ? now()->addHours(6) : now()->addMinutes(10));

        return $nodeId;
    }

    private function resolveServerMeta(int $nodeId): ?array
    {
        $cacheKey = 'user_report:server_meta:' . $nodeId;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $server = Server::query()->where('id', $nodeId)->select(['id', 'host', 'type'])->first();
        if ($server === null) {
            Cache::put($cacheKey, null, now()->addMinutes(10));
            return null;
        }

        $value = [
            'id' => (int) $server->id,
            'host' => (string) ($server->host ?? ''),
            'type' => (string) ($server->type ?? ''),
        ];
        Cache::put($cacheKey, $value, now()->addHours(6));

        return $value;
    }

    private function normalizeNodeHost($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $host = strtolower(trim($value));
        if ($host === '') {
            return '';
        }

        if (str_starts_with($host, 'http://') || str_starts_with($host, 'https://')) {
            $parsed = parse_url($host, PHP_URL_HOST);
            if (is_string($parsed) && $parsed !== '') {
                $host = strtolower($parsed);
            }
        }

        if (preg_match('/^([^:]+):(\d+)$/', $host, $match) === 1) {
            $host = $match[1];
        }

        if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $host, $match) === 1) {
            $host = $match[1];
        }

        return $host;
    }

    private function extractVpnConnectionData($userDefault): array
    {
        $entries = $userDefault;
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
            if (!in_array($type, ['vpn_connection', 'vpn_connect'], true)) {
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

        return max(0, $hours * 3600 + $minutes * 60 + $seconds);
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

        $text = str_replace([' ', ','], ['', '.'], $text);
        if (!preg_match('/([0-9]+(?:\.[0-9]+)?)/', $text, $m)) {
            return 0.0;
        }

        $num = (float) $m[1];
        if ($num <= 0) {
            return 0.0;
        }

        if (str_contains($text, 'gib') || str_contains($text, 'gb')) {
            return round($num * 1024, 3);
        }
        if (str_contains($text, 'kib') || str_contains($text, 'kb')) {
            return round($num / 1024, 3);
        }
        if (str_contains($text, 'b') && !str_contains($text, 'mb')) {
            return round($num / 1024 / 1024, 3);
        }

        return round($num, 3);
    }

    private function bumpCacheVersion(string $table, string $date, int $hour): void
    {
        $versionKey = sprintf('user_report:qv:%s:%s:%02d', $table, $date, $hour);
        Cache::increment($versionKey);
        Cache::put($versionKey, (int) Cache::get($versionKey, 1), now()->addDays(7));
    }
}
