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

        // 1. 从 Redis 弹出原始数据
        $rawRecords = NodePerformanceService::popRawReports($batchSize);

        if (empty($rawRecords)) {
            $this->info('No raw reports in Redis.');
            return self::SUCCESS;
        }

        $this->info("Popped " . count($rawRecords) . " raw reports from Redis.");

        // 2. 归档原始数据到 OSS
        $this->archiveToOss($rawRecords);

        // 3. 聚合
        $now = Carbon::now();
        // 对齐到 5 分钟窗口
        $minute = (int) floor($now->minute / 5) * 5;
        $date = $now->toDateString();
        $hour = $now->hour;

        $grouped = collect($rawRecords)->groupBy(function ($record) {
            return implode('|', [
                $record['node_id'] ?? 0,
                $record['client_country'] ?? '',
                $record['client_city'] ?? '',
                $record['platform'] ?? '',
                $record['client_isp'] ?? '',
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
                'client_city'      => $first['client_city'] ?? null,
                'platform'         => $first['platform'] ?? null,
                'client_isp'       => $first['client_isp'] ?? null,
                'avg_success_rate' => round($avgSuccessRate, 2),
                'avg_delay'        => round($avgDelay, 2),
                'total_count'      => $totalCount,
            ];
        }

        // 4. 写入 DB（upsert：同维度累加）
        foreach ($upsertData as $row) {
            DB::table('v2_node_performance_aggregated')->updateOrInsert(
                [
                    'date'           => $row['date'],
                    'hour'           => $row['hour'],
                    'minute'         => $row['minute'],
                    'node_id'        => $row['node_id'],
                    'client_country' => $row['client_country'],
                    'client_city'    => $row['client_city'],
                    'platform'       => $row['platform'],
                    'client_isp'     => $row['client_isp'],
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

        Log::info('perf:aggregate completed', [
            'raw_count'       => count($rawRecords),
            'dimension_count' => count($upsertData),
            'date'            => $date,
            'hour'            => $hour,
            'minute'          => $minute,
        ]);

        return self::SUCCESS;
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
