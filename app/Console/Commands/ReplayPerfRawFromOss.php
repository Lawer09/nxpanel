<?php

namespace App\Console\Commands;

use App\Services\NodePerformanceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class ReplayPerfRawFromOss extends Command
{
    protected $signature = 'perf:replay-oss
        {date : YYYY-MM-DD}
        {--hour= : Optional hour, 00-23}
        {--chunk=3000 : Replay chunk size}
        {--batch=5000 : Batch for perf:aggregate}
        {--temp : Also write to *_temp tables}
        {--dry-run : Only count records without writing}
        {--clear-day : Clear aggregated tables for the day first}';

    protected $description = 'Replay perf/raw NDJSON from OSS and re-aggregate';

    public function handle(): int
    {
        $date = (string) $this->argument('date');
        $hour = $this->option('hour');
        $chunkSize = max(100, (int) $this->option('chunk'));
        $batchSize = max(1000, (int) $this->option('batch'));
        $writeTemp = (bool) $this->option('temp');
        $dryRun = (bool) $this->option('dry-run');
        $clearDay = (bool) $this->option('clear-day');

        try {
            $day = Carbon::createFromFormat('Y-m-d', $date);
        } catch (\Throwable $e) {
            $this->error('Invalid date format. Use YYYY-MM-DD, for example: 2026-05-06');
            return self::FAILURE;
        }

        $prefix = sprintf('perf/raw/%s/%s/%s', $day->format('Y'), $day->format('m'), $day->format('d'));
        $files = Storage::disk('oss')->files($prefix);

        if ($hour !== null && $hour !== '') {
            $hourText = str_pad((string) ((int) $hour), 2, '0', STR_PAD_LEFT);
            $files = array_values(array_filter($files, function ($path) use ($hourText) {
                $base = basename($path);
                return str_starts_with($base, $hourText . '-');
            }));
        }

        if (empty($files)) {
            $this->warn('No OSS files found under: ' . $prefix);
            return self::SUCCESS;
        }

        sort($files);
        $this->info('Matched files: ' . count($files));

        if ($clearDay && !$dryRun) {
            $this->warn('Clearing aggregated rows for date: ' . $date);
            DB::table('v2_node_performance_aggregated')->where('date', $date)->delete();
            DB::table('v2_node_probe_aggregated')->where('date', $date)->delete();
            DB::table('v2_node_traffic_aggregated')->where('date', $date)->delete();
            DB::table('v3_user_report_count')->where('date', $date)->delete();

            if ($writeTemp) {
                DB::table('v2_node_performance_aggregated_temp')->where('date', $date)->delete();
                DB::table('v2_node_probe_aggregated_temp')->where('date', $date)->delete();
                DB::table('v2_node_traffic_aggregated_temp')->where('date', $date)->delete();
                DB::table('v3_user_report_count_temp')->where('date', $date)->delete();
            }
        }

        $targetBucketKey = NodePerformanceService::bucketKeyAt(now()->subMinutes(5));
        $totalRecords = 0;
        $buffer = [];

        foreach ($files as $file) {
            $content = Storage::disk('oss')->get($file);
            $lines = preg_split("/\r\n|\n|\r/", trim($content));
            if (!$lines) {
                continue;
            }

            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                if (!is_array($record)) {
                    continue;
                }

                $payload = $this->buildPayloadFromRawRecord($record);
                if ($payload === null) {
                    continue;
                }

                $buffer[] = $payload;
                $totalRecords++;

                if (count($buffer) >= $chunkSize) {
                    $this->flushChunk($targetBucketKey, $buffer, $batchSize, $dryRun, $writeTemp);
                    $buffer = [];
                }
            }
        }

        if (!empty($buffer)) {
            $this->flushChunk($targetBucketKey, $buffer, $batchSize, $dryRun, $writeTemp);
        }

        $this->info('Replay completed. Total records: ' . $totalRecords);
        return self::SUCCESS;
    }

    private function buildPayloadFromRawRecord(array $record): ?array
    {
        $userId = (int) ($record['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $timestampMs = (int) ($record['event_timestamp_ms'] ?? 0);
        $nowMs = now()->getTimestampMs();
        if ($timestampMs <= 0 || $timestampMs > $nowMs) {
            $timestampMs = $nowMs;
        }

        return [
            'user_id' => $userId,
            'node_id' => (int) ($record['node_id'] ?? 0),
            'node_ip' => $record['node_ip'] ?? null,
            'delay' => max(0, (int) ($record['delay'] ?? 0)),
            'success_rate' => (int) ($record['success_rate'] ?? 0),
            'client_ip' => $record['client_ip'] ?? null,
            'client_country' => $record['client_country'] ?? null,
            'client_city' => $record['client_city'] ?? null,
            'client_isp' => $record['client_isp'] ?? null,
            'platform' => $record['platform'] ?? null,
            'app_id' => $record['app_id'] ?? null,
            'app_version' => $record['app_version'] ?? null,
            'status' => $record['status'] ?? null,
            'probe_stage' => $record['probe_stage'] ?? null,
            'error_code' => $record['error_code'] ?? null,
            'vpn_user_time' => (int) ($record['vpn_user_time_seconds'] ?? 0),
            'vpn_user_traffic' => (float) ($record['vpn_user_traffic_mb'] ?? 0),
            'arise_timestamp' => $record['arise_timestamp_ms'] ?? null,
            'reported_at' => $timestampMs,
            'metadata' => [
                'timestamp' => $timestampMs,
                'country' => $record['client_country'] ?? null,
                'isp' => $record['client_isp'] ?? null,
                'platform' => $record['platform'] ?? null,
                'app_id' => $record['app_id'] ?? null,
                'app_version' => $record['app_version'] ?? null,
            ],
            'created_at' => now()->toDateTimeString(),
        ];
    }

    private function flushChunk(string $bucketKey, array $payloads, int $batchSize, bool $dryRun, bool $writeTemp): void
    {
        $count = count($payloads);
        $this->line('Processing chunk: ' . $count);

        if ($dryRun) {
            return;
        }

        Redis::del($bucketKey);
        foreach ($payloads as $payload) {
            Redis::rpush($bucketKey, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }
        Redis::expire($bucketKey, 1800);

        $arguments = [
            '--batch' => max($batchSize, $count + 10),
        ];

        if ($writeTemp) {
            $arguments['--temp'] = true;
        }

        Artisan::call('perf:aggregate', $arguments);

        $output = trim(Artisan::output());
        if ($output !== '') {
            $this->line($output);
        }
    }
}
