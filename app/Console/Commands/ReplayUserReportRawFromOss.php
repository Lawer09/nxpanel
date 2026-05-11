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
        {--bucket= : Optional bucket yyyymmddHHmm}
        {--clear-day : Clear v3_user_report_node for this day before replay}
        {--dry-run : Only count records, no write}';

    protected $description = 'Replay user-report raw NDJSON from OSS and re-aggregate';

    public function handle(): int
    {
        $date = (string) $this->argument('date');
        $hour = $this->option('hour');
        $bucketFilter = $this->option('bucket');
        $dryRun = (bool) $this->option('dry-run');

        try {
            Carbon::createFromFormat('Y-m-d', $date);
        } catch (\Throwable $e) {
            $this->error('Invalid date format. Use YYYY-MM-DD');
            return self::FAILURE;
        }

        if ($bucketFilter !== null && preg_match('/^\d{12}$/', (string) $bucketFilter) !== 1) {
            $this->error('Invalid --bucket. Expected yyyymmddHHmm');
            return self::FAILURE;
        }

        $parts = explode('-', $date);
        $prefix = sprintf('user_report/raw/%s/%s/%s', $parts[0], $parts[1], $parts[2]);
        $files = Storage::disk('oss')->allFiles($prefix);
        if (empty($files)) {
            $this->warn('No OSS files found under: ' . $prefix);
            return self::SUCCESS;
        }

        sort($files);

        $clearDay = (bool) $this->option('clear-day');

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

        if ($clearDay && !$dryRun) {
            $this->warn('Clearing v3_user_report_node for date: ' . $date);
            DB::table('v3_user_report_node')->where('date', $date)->delete();
        }

        if (empty($bucketPayloads)) {
            $this->warn('No payloads matched filter conditions.');
            return self::SUCCESS;
        }

        ksort($bucketPayloads);

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

        $this->info("Replay done. buckets={$totalBuckets}, payloads={$totalPayloads}");
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
