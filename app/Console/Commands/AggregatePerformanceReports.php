<?php

namespace App\Console\Commands;

use App\Models\NodePerformanceAggregated;
use App\Services\NodePerformanceService;
use App\Services\OssArchiveService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
        $nodeRecords = array_values(array_filter($rawRecords, fn($record) => (int) ($record['node_id'] ?? 0) > 0));

        $grouped = collect($nodeRecords)->groupBy(function ($record) {
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

        // 6. 用户上报次数统计
        $this->aggregateUserReportCount($rawRecords, $date, $hour, $minute);

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
                    'reported_at' => $reportedAt,
                    'created_at' => $createdAt,
                ];
                continue;
            }

            foreach ($reports as $report) {
                if (!is_array($report)) {
                    continue;
                }

                $flattened[] = [
                    'user_id' => $userId,
                    'node_id' => (int) ($report['node_id'] ?? 0),
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
                    'reported_at' => $reportedAt,
                    'created_at' => $createdAt,
                ];
            }
        }

        return $flattened;
    }

    /**
     * 按用户聚合上报次数，写入 v3_user_report_count
     */
    private function aggregateUserReportCount(array $rawRecords, string $date, int $hour, int $minute): void
    {
        $userGrouped = collect($rawRecords)->groupBy('user_id');

        foreach ($userGrouped as $userId => $items) {
            $nodeCount = $items->pluck('node_id')->filter(fn($id) => (int) $id > 0)->unique()->count();
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
                    'node_count'     => $nodeCount,
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

        // $deletedUser = DB::table('v3_user_report_count')
        //     ->where('date', '<', $cutoff)
        //     ->delete();
    
        $deletedUser = 0; // 暂不删除用户上报统计数据，保留历史查询能力    
        
        if ($deletedAgg > 0 || $deletedUser > 0) {
            $this->info("Pruned old data (before {$cutoff}): aggregated={$deletedAgg}, user_report={$deletedUser}");
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
