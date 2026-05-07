<?php

namespace App\Console\Commands;

use App\Services\UserReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DebugUserReportVpnFromOss extends Command
{
    protected $signature = 'user_report:debug-vpn-oss
        {date : YYYY-MM-DD}
        {--hour= : Optional hour 00-23}
        {--minute= : Optional minute 00/05/10...55}
        {--bucket= : Optional bucket yyyymmddHHmm}
        {--app-id= : Optional app_id filter}
        {--limit=50 : Max rows to print}';

    protected $description = 'Debug vpn_connection rows from user_report OSS raw files';

    public function handle(): int
    {
        $date = (string) $this->argument('date');
        $hour = $this->option('hour');
        $minute = $this->option('minute');
        $bucketFilter = $this->option('bucket');
        $appIdFilter = trim((string) ($this->option('app-id') ?? ''));
        $limit = max(1, (int) $this->option('limit'));

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
        $this->info('Matched files: ' . count($files));

        $parsedPayloads = 0;
        $payloadsWithUserDefault = 0;
        $matchedPayloads = 0;
        $matchedVpnEntries = 0;
        $printed = 0;

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

                $payload = json_decode($line, true);
                if (!is_array($payload)) {
                    continue;
                }
                $parsedPayloads++;

                if (array_key_exists('user_default', $payload)) {
                    $payloadsWithUserDefault++;
                }

                $metadata = $this->resolveMetadata($payload);
                $appId = (string) ($metadata['app_id'] ?? '');
                if ($appIdFilter !== '' && $appId !== $appIdFilter) {
                    continue;
                }

                $reportAtMs = UserReportService::resolveReportAtMs($metadata);
                $bucketTime = Carbon::createFromTimestampMsUTC($reportAtMs)->setTimezone('Asia/Shanghai');
                $bucketMinute = (int) floor(((int) $bucketTime->minute) / 5) * 5;
                $bucket = $bucketTime->copy()->second(0)->minute($bucketMinute)->format('YmdHi');
                $bucketHour = $bucketTime->format('H');
                $bucketMinuteText = str_pad((string) $bucketMinute, 2, '0', STR_PAD_LEFT);

                if ($bucketFilter !== null && $bucket !== (string) $bucketFilter) {
                    continue;
                }
                if ($hour !== null && $bucketHour !== str_pad((string) ((int) $hour), 2, '0', STR_PAD_LEFT)) {
                    continue;
                }
                if ($minute !== null && $bucketMinuteText !== str_pad((string) ((int) $minute), 2, '0', STR_PAD_LEFT)) {
                    continue;
                }

                $entries = $this->extractVpnEntries($payload['user_default'] ?? null);
                if (empty($entries)) {
                    continue;
                }

                $matchedPayloads++;
                $matchedVpnEntries += count($entries);

                foreach ($entries as $entry) {
                    if ($printed >= $limit) {
                        break 3;
                    }

                    $row = [
                        'path' => $path,
                        'bucket' => $bucket,
                        'user_id' => (int) ($payload['user_id'] ?? 0),
                        'app_id' => $appId,
                        'app_version' => (string) ($metadata['app_version'] ?? ''),
                        'country' => (string) ($metadata['country'] ?? ''),
                        'vpn_connection' => $entry,
                    ];

                    $this->line(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $printed++;
                }
            }
        }

        $this->info(sprintf(
            'Done. parsed_payloads=%d payloads_with_user_default=%d payloads_with_vpn=%d vpn_entries=%d printed=%d',
            $parsedPayloads,
            $payloadsWithUserDefault,
            $matchedPayloads,
            $matchedVpnEntries,
            $printed
        ));

        return self::SUCCESS;
    }

    private function extractVpnEntries($userDefault): array
    {
        $entries = $userDefault;
        if (is_string($entries)) {
            $decoded = json_decode($entries, true);
            $entries = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($entries)) {
            return [];
        }

        if (array_key_exists('type', $entries) || array_key_exists('data', $entries)) {
            $entries = [$entries];
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

    private function resolveMetadata(array $payload): array
    {
        $metadata = $payload['metadata'] ?? null;
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
