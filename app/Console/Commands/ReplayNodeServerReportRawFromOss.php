<?php

namespace App\Console\Commands;

use App\Services\NodeServerReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReplayNodeServerReportRawFromOss extends Command
{
    protected $signature = 'node_server_report:replay-oss
        {date : YYYY-MM-DD start date}
        {--to= : Optional end date YYYY-MM-DD (default: same as date)}
        {--hour= : Optional hour 00-23}
        {--minute= : Optional minute 00/05/10...55}
        {--bucket= : Optional bucket yyyymmddHHmm}
        {--batch=10000 : Batch size for dispatch command}
        {--chunk=1000 : Chunk size for queue dispatch}
        {--dry-run : Only count records, no write}
        {--clear-day : Clear v3 node server report tables for replay dates}';

    protected $description = 'Replay node-server-report raw NDJSON from OSS and redispatch';

    public function handle(): int
    {
        $dateFrom = (string) $this->argument('date');
        $dateTo = $this->option('to') ?? $dateFrom;
        $hour = $this->option('hour');
        $minute = $this->option('minute');
        $bucketFilter = $this->option('bucket');
        $batchSize = max(1000, (int) $this->option('batch'));
        $chunkSize = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $clearDay = (bool) $this->option('clear-day');

        try {
            $start = Carbon::createFromFormat('Y-m-d', $dateFrom);
            $end = Carbon::createFromFormat('Y-m-d', $dateTo);
        } catch (\Throwable $e) {
            $this->error('date/--to 格式错误，请使用 YYYY-MM-DD');
            return self::FAILURE;
        }

        if ($start->gt($end)) {
            $this->error('date 不能晚于 --to');
            return self::FAILURE;
        }

        if ($bucketFilter !== null && preg_match('/^\d{12}$/', (string) $bucketFilter) !== 1) {
            $this->error('Invalid --bucket. Expected yyyymmddHHmm');
            return self::FAILURE;
        }

        if ($minute !== null) {
            $minuteInt = (int) $minute;
            if ($minuteInt < 0 || $minuteInt > 59 || $minuteInt % 5 !== 0) {
                $this->error('Invalid --minute. Expected 0..59 and divisible by 5');
                return self::FAILURE;
            }
        }

        $totalPayloads = 0;
        $totalBuckets = 0;

        $current = clone $start;
        while ($current->lte($end)) {
            $date = $current->toDateString();
            $this->line("--- Processing date: {$date} ---");

            $parts = explode('-', $date);
            $prefix = sprintf('node_server_report/raw/%s/%s/%s', $parts[0], $parts[1], $parts[2]);
            $files = Storage::disk('oss')->allFiles($prefix);
            if (empty($files)) {
                $this->warn("No OSS files found under: {$prefix}");
                $current->addDay();
                continue;
            }

            sort($files);

            $bucketPayloads = [];
            foreach ($files as $path) {
                $content = Storage::disk('oss')->get($path);
                $lines = preg_split("/\r\n|\n|\r/", trim((string) $content));
                if (empty($lines)) {
                    continue;
                }

                foreach ($lines as $line) {
                    if ($line === '') {
                        continue;
                    }

                    $row = json_decode($line, true);
                    if (!is_array($row)) {
                        continue;
                    }

                    $reportAtMs = $this->resolveReportAtMs($row);
                    $bucketTime = Carbon::createFromTimestampMsUTC($reportAtMs)->setTimezone('Asia/Shanghai');
                    $bucketMinute = (int) floor(((int) $bucketTime->minute) / 5) * 5;
                    $bucket = $bucketTime->copy()->second(0)->minute($bucketMinute)->format('YmdHi');

                    if ($bucketFilter !== null && $bucket !== (string) $bucketFilter) {
                        continue;
                    }

                    $bucketHour = $bucketTime->format('H');
                    $bucketMinuteText = str_pad((string) $bucketMinute, 2, '0', STR_PAD_LEFT);
                    if ($hour !== null && $bucketHour !== str_pad((string) ((int) $hour), 2, '0', STR_PAD_LEFT)) {
                        continue;
                    }
                    if ($minute !== null && $bucketMinuteText !== str_pad((string) ((int) $minute), 2, '0', STR_PAD_LEFT)) {
                        continue;
                    }

                    $bucketPayloads[$bucket][] = $row;
                }
            }

            if (empty($bucketPayloads)) {
                $this->warn("No payloads matched for date: {$date}");
                $current->addDay();
                continue;
            }

            ksort($bucketPayloads);

            if ($clearDay && !$dryRun) {
                $this->line('Clearing day data before replay: ' . $date);
                DB::table('v3_node_server_report_node')->where('date', $date)->delete();
                DB::table('v3_node_server_report_user')->where('date', $date)->delete();
            }

            $this->info("Matched buckets: " . count($bucketPayloads));

            foreach ($bucketPayloads as $bucket => $payloads) {
                $count = count($payloads);
                if ($count === 0) {
                    $this->line("bucket={$bucket} payloads=0 skip");
                    continue;
                }

                $totalPayloads += $count;
                $totalBuckets++;

                if ($dryRun) {
                    $this->line("[dry-run] bucket={$bucket} payloads={$count}");
                    continue;
                }

                $bucketKey = NodeServerReportService::REDIS_RAW_PREFIX . $bucket;
                NodeServerReportService::deleteBucket($bucketKey);
                NodeServerReportService::restoreBucket($bucketKey, $payloads);

                $dispatchBatch = max($batchSize, $count + 10);
                $exitCode = Artisan::call('node_server_report:dispatch', [
                    '--bucket' => $bucket,
                    '--batch' => $dispatchBatch,
                    '--chunk' => $chunkSize,
                ]);

                $output = trim(Artisan::output());
                if ($output !== '') {
                    $this->line($output);
                }

                if ($exitCode !== self::SUCCESS) {
                    $this->error("dispatch failed: bucket={$bucket}, exit={$exitCode}");
                    return self::FAILURE;
                }

                $this->line("replayed bucket={$bucket} payloads={$count}");
            }

            $current->addDay();
        }

        $this->info("Replay done. total_dates=" . $start->diffInDays($end) + 1 . ", buckets={$totalBuckets}, payloads={$totalPayloads}");
        return self::SUCCESS;
    }

    private function resolveReportAtMs(array $row): int
    {
        $value = $row['report_at_ms'] ?? $row['reported_at'] ?? null;
        if (!is_numeric($value)) {
            return now()->getTimestampMs();
        }

        $int = (int) $value;
        if ($int <= 0) {
            return now()->getTimestampMs();
        }

        return $int < 1000000000000 ? $int * 1000 : $int;
    }
}
