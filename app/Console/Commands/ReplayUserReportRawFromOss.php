<?php

namespace App\Console\Commands;

use App\Services\UserReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ReplayUserReportRawFromOss extends Command
{
    protected $signature = 'user_report:replay-oss
        {date : YYYY-MM-DD}
        {--hour= : Optional hour 00-23}
        {--minute= : Optional minute 00/05/10...55}
        {--bucket= : Optional bucket yyyymmddHHmm}
        {--batch=10000 : Batch size for aggregate command}
        {--dry-run : Only count records, no write}
        {--clear-day : Clear v3 user report tables for this day before replay}';

    protected $description = 'Replay user-report raw NDJSON from OSS and re-aggregate';

    public function handle(): int
    {
        $date = (string) $this->argument('date');
        $hour = $this->option('hour');
        $minute = $this->option('minute');
        $bucketFilter = $this->option('bucket');
        $batchSize = max(1000, (int) $this->option('batch'));
        $dryRun = (bool) $this->option('dry-run');
        $clearDay = (bool) $this->option('clear-day');

        try {
            Carbon::createFromFormat('Y-m-d', $date);
        } catch (\Throwable $e) {
            $this->error('Invalid date format. Use YYYY-MM-DD, e.g. 2026-05-07');
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

        $parts = explode('-', $date);
        $prefix = sprintf('user_report/raw/%s/%s/%s', $parts[0], $parts[1], $parts[2]);
        $files = Storage::disk('oss')->allFiles($prefix);
        if (empty($files)) {
            $this->warn('No OSS files found under: ' . $prefix);
            return self::SUCCESS;
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

                $metadata = $this->resolveMetadata($row);
                $reportAtMs = UserReportService::resolveReportAtMs($metadata);
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
            $this->warn('No payloads matched filter conditions.');
            return self::SUCCESS;
        }

        ksort($bucketPayloads);

        if ($clearDay && !$dryRun) {
            $this->warn('Clearing day data before replay: ' . $date);
            DB::table('v3_user_report_summary')->where('date', $date)->delete();
            DB::table('v3_user_report_node_summary')->where('date', $date)->delete();
            DB::table('v3_user_report_traffic')->where('date', $date)->delete();
            DB::table('v3_user_report_node_fail')->where('date', $date)->delete();
        }

        $totalPayloads = 0;
        $totalBuckets = 0;
        $this->info('Matched buckets: ' . count($bucketPayloads));

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

            $bucketKey = UserReportService::REDIS_RAW_PREFIX . $bucket;
            UserReportService::deleteBucket($bucketKey);
            UserReportService::restoreBucket($bucketKey, $payloads);

            $aggregateBatch = max($batchSize, $count + 10);
            $exitCode = Artisan::call('user_report:aggregate', [
                '--bucket' => $bucket,
                '--batch' => $aggregateBatch,
                '--skip-archive' => true,
            ]);

            $output = trim(Artisan::output());
            if ($output !== '') {
                $this->line($output);
            }

            if ($exitCode !== self::SUCCESS) {
                $this->error("aggregate failed: bucket={$bucket}, exit={$exitCode}");
                return self::FAILURE;
            }

            $this->line("replayed bucket={$bucket} payloads={$count}");
        }

        $this->info("Replay done. buckets={$totalBuckets}, payloads={$totalPayloads}");
        return self::SUCCESS;
    }

    private function resolveMetadata(array $row): array
    {
        $metadata = $row['metadata'] ?? null;
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
