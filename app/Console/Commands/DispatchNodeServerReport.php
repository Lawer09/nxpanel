<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNodeServerReportBatchJob;
use App\Services\NodeServerReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DispatchNodeServerReport extends Command
{
    protected $signature = 'node_server_report:dispatch
        {--batch=10000 : 每次读取 Redis 的最大 payload 条数}
        {--chunk=1000 : 每个队列任务包含的 payload 条数}
        {--bucket= : 指定桶时间(yyyyMMddHHmm, UTC+8)}';

    protected $description = '节点上报数据派发（先归档OSS，再投递队列处理）';

    public function handle(): int
    {
        if (!NodeServerReportService::enabled()) {
            $this->info('NODE_SERVER_REPORT_ENABLED=false, skip.');
            return self::SUCCESS;
        }

        $batch = max(100, (int) $this->option('batch'));
        $chunkSize = max(100, (int) $this->option('chunk'));

        $bucketTime = $this->resolveTargetBucketTime();
        $bucketKey = NodeServerReportService::bucketKeyAtUtc8($bucketTime);
        $lockKey = 'node_server_report:dispatch:lock:' . $bucketTime->format('YmdHi');

        $lock = Cache::lock($lockKey, 240);
        if (!$lock->get()) {
            $this->warn('bucket is locked: ' . $bucketKey);
            return self::SUCCESS;
        }

        try {
            $payloads = NodeServerReportService::readBucket($bucketKey, $batch);
            if (empty($payloads)) {
                $this->info('No payloads in bucket: ' . $bucketKey);
                return self::SUCCESS;
            }

            $archivePath = $this->archiveRawPayloads($payloads);
            if ($archivePath === null) {
                $this->error('Archive raw payloads failed, keep bucket for retry: ' . $bucketKey);
                return self::FAILURE;
            }

            foreach (array_chunk($payloads, $chunkSize) as $chunk) {
                ProcessNodeServerReportBatchJob::dispatch($chunk);
            }

            NodeServerReportService::deleteBucket($bucketKey);

            Log::info(sprintf(
                'node_server_report dispatch done: bucket=%s payloads=%d archive=%s',
                $bucketKey,
                count($payloads),
                $archivePath
            ));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('node_server_report:dispatch failed', [
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
        if ($bucket !== null && preg_match('/^\d{12}$/', (string) $bucket) === 1) {
            return Carbon::createFromFormat('YmdHi', (string) $bucket, 'Asia/Shanghai')->second(0);
        }

        $nowUtc8 = Carbon::now('Asia/Shanghai');
        $alignedMinute = (int) floor(((int) $nowUtc8->minute) / 5) * 5;
        return $nowUtc8->copy()->minute($alignedMinute)->second(0)->subMinutes(5);
    }

    private function archiveRawPayloads(array $payloads): ?string
    {
        if (!config('filesystems.disks.oss.enabled', false)) {
            $this->warn('OSS not enabled, skip archive and stop dispatch.');
            return null;
        }

        $path = sprintf(
            'node_server_report/raw/%s/%s/%s/%s_%s.ndjson',
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
                Log::warning('node_server_report raw archive failed', ['path' => $path]);
                return null;
            }
            return $path;
        } catch (\Throwable $e) {
            Log::error('node_server_report raw archive exception', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
