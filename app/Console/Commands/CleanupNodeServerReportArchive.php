<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupNodeServerReportArchive extends Command
{
    protected $signature = 'node_server_report:cleanup-archive
        {--date= : 清理指定归档日期 YYYY-MM-DD 下的文件}
        {--from= : 起始归档日期 YYYY-MM-DD}
        {--to= : 截止归档日期 YYYY-MM-DD}
        {--dry-run : 仅统计，不删除}
        {--force : 跳过确认提示}';

    protected $description = '清理 node_server_report OSS 归档中的重复文件（归档日期 > 数据日期 = replay 产物）';

    public function handle(): int
    {
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $dates = $this->resolveDateRange($date, $from, $to);
        if (empty($dates)) {
            $this->error('请指定 --date, --from/--to 之一');
            return self::FAILURE;
        }

        $totalFiles = 0;
        $totalDeleted = 0;
        $totalSize = 0;
        $duplicateFiles = [];

        foreach ($dates as $archiveDate) {
            $parts = explode('-', $archiveDate);
            $prefix = sprintf('node_server_report/raw/%s/%s/%s', $parts[0], $parts[1], $parts[2]);
            $files = Storage::disk('oss')->allFiles($prefix);

            if (empty($files)) {
                $this->warn("No files under: {$prefix}");
                continue;
            }

            sort($files);

            foreach ($files as $path) {
                $totalFiles++;
                $content = Storage::disk('oss')->get($path);
                $lines = preg_split("/\r\n|\n|\r/", trim((string) $content));
                if (empty($lines)) {
                    continue;
                }

                $size = strlen((string) $content);
                $earliestDataDate = null;
                $latestDataDate = null;
                $linesChecked = 0;

                foreach ($lines as $line) {
                    if ($line === '') {
                        continue;
                    }

                    $row = json_decode($line, true);
                    if (!is_array($row)) {
                        continue;
                    }

                    $reportAtMs = $this->resolveReportAtMs($row);
                    $dataDate = Carbon::createFromTimestampMsUTC($reportAtMs)->setTimezone('Asia/Shanghai')->toDateString();

                    if ($earliestDataDate === null || $dataDate < $earliestDataDate) {
                        $earliestDataDate = $dataDate;
                    }
                    if ($latestDataDate === null || $dataDate > $latestDataDate) {
                        $latestDataDate = $dataDate;
                    }

                    $linesChecked++;
                    if ($linesChecked >= 10) {
                        break;
                    }
                }

                if ($earliestDataDate === null || $latestDataDate === null) {
                    $this->warn("Cannot parse data date in: {$path}");
                    continue;
                }

                if ($archiveDate > $latestDataDate) {
                    $duplicateFiles[] = [
                        'path' => $path,
                        'archive_date' => $archiveDate,
                        'data_date_range' => "{$earliestDataDate} ~ {$latestDataDate}",
                        'size' => $size,
                        'lines' => count($lines),
                    ];
                }
            }
        }

        if (empty($duplicateFiles)) {
            $this->info("No duplicate archive files found. total_files={$totalFiles}");
            return self::SUCCESS;
        }

        $this->table(
            ['Archive Date', 'Data Date Range', 'Lines', 'Size', 'Path'],
            collect($duplicateFiles)->map(fn($f) => [
                $f['archive_date'],
                $f['data_date_range'],
                $f['lines'],
                $this->formatBytes($f['size']),
                $f['path'],
            ])
        );

        $totalDuplicateSize = array_sum(array_column($duplicateFiles, 'size'));
        $this->info(sprintf(
            'Found %d duplicate files, total size %s (total files scanned: %d)',
            count($duplicateFiles),
            $this->formatBytes($totalDuplicateSize),
            $totalFiles
        ));

        if ($dryRun) {
            $this->line('--dry-run mode, no files deleted.');
            return self::SUCCESS;
        }

        if (!$force && !$this->confirm('Delete these ' . count($duplicateFiles) . ' files?')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        foreach ($duplicateFiles as $f) {
            if (Storage::disk('oss')->delete($f['path'])) {
                $totalDeleted++;
                $totalSize += $f['size'];
                $this->line("Deleted: {$f['path']}");
            } else {
                $this->error("Failed to delete: {$f['path']}");
            }
        }

        $this->info(sprintf(
            'Cleanup done. deleted=%d files, freed %s',
            $totalDeleted,
            $this->formatBytes($totalSize)
        ));

        return self::SUCCESS;
    }

    private function resolveDateRange(?string $date, ?string $from, ?string $to): array
    {
        if ($date !== null) {
            return [$date];
        }

        if ($from === null) {
            return [];
        }

        $start = Carbon::createFromFormat('Y-m-d', $from);
        $end = $to !== null
            ? Carbon::createFromFormat('Y-m-d', $to)
            : clone $start;

        if ($start->gt($end)) {
            return [];
        }

        $dates = [];
        $current = clone $start;
        while ($current->lte($end)) {
            $dates[] = $current->toDateString();
            $current->addDay();
        }

        return $dates;
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
