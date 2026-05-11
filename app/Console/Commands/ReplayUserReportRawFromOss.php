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
        {date : YYYY-MM-DD start date}
        {--to= : Optional end date YYYY-MM-DD (default: same as date)}
        {--hour= : Optional hour 00-23}
        {--bucket= : Optional bucket yyyymmddHHmm}
        {--clear-day : Clear v3_user_report_node for replay dates}
        {--dry-run : Only count records, no write}';

    protected $description = 'Replay user-report raw NDJSON from OSS and re-aggregate';

    public function handle(): int
    {
        $dateFrom = (string) $this->argument('date');
        $dateTo = $this->option('to') ?? $dateFrom;
        $hour = $this->option('hour');
        $bucketFilter = $this->option('bucket');
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

        $totalPayloads = 0;
        $totalBuckets = 0;

        $current = clone $start;
        while ($current->lte($end)) {
            $date = $current->toDateString();
            $this->line("--- Processing date: {$date} ---");

            $parts = explode('-', $date);
            $prefix = sprintf('user_report/raw/%s/%s/%s', $parts[0], $parts[1], $parts[2]);
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
                    if ($hour !== null && $bucketHour !== str_pad((string) ((int) $hour), 2, '0', STR_PAD_LEFT)) {
                        continue;
                    }

                    $bucketPayloads[$bucket][] = $row;
                }
            }

            if ($clearDay && !$dryRun && !empty($bucketPayloads)) {
                $this->line('Clearing v3_user_report_node for date: ' . $date);
                DB::table('v3_user_report_node')->where('date', $date)->delete();
            }

            if (empty($bucketPayloads)) {
                $this->warn("No payloads matched for date: {$date}");
                $current->addDay();
                continue;
            }

            ksort($bucketPayloads);

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

                $bucketKey = UserReportService::REDIS_RAW_PREFIX . $bucket;
                UserReportService::deleteBucket($bucketKey);
                UserReportService::restoreBucket($bucketKey, $payloads);

                $exitCode = Artisan::call('user_report:aggregate', [
                    '--bucket' => $bucket,
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

            $current->addDay();
        }

        $this->info("Replay done. total_dates=" . ($start->diffInDays($end) + 1) . ", buckets={$totalBuckets}, payloads={$totalPayloads}");
        return self::SUCCESS;
    }

    private function resolveReportAtMs(array $row): int
    {
        $metadata = $row['metadata'] ?? null;
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return UserReportService::resolveReportAtMs($metadata);
    }
}
