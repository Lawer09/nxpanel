<?php

namespace App\Services;

use App\Models\AdSpendPlatformAccount;
use App\Models\AdSpendSyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdSpendSyncService
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SCHEDULED = 'scheduled';
    private const UPSERT_BATCH_SIZE = 500;
    private const FETCH_PAGE_SIZE = 500;

    /**
     * Sync ad spend daily reports for one account and one date range.
     */
    public function syncAccount(
        AdSpendPlatformAccount $account,
        string $startDate,
        string $endDate,
        AdSpendPlatformService $platformService,
        string $source = self::SOURCE_SCHEDULED
    ): AdSpendSyncJob {
        $requestParams = [
            'objectName' => 'account',
            'dims' => ['date', 'group_name', 'group_id', 'country'],
            'startDate' => $startDate,
            'endDate' => $endDate,
            'size' => self::FETCH_PAGE_SIZE,
        ];

        $job = AdSpendSyncJob::create([
            'platform_account_id' => $account->id,
            'platform_code' => $account->platform_code,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => AdSpendSyncJob::STATUS_RUNNING,
            'request_params' => $requestParams,
            'total_records' => 0,
            'matched_records' => 0,
            'unmatched_records' => 0,
        ]);

        Log::info('Ad spend sync started', [
            'source' => $source,
            'job_id' => $job->id,
            'account_id' => $account->id,
            'platform_code' => $account->platform_code,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        try {
            $projectCodeLookup = $this->loadProjectCodeLookup();

            $totalRecords = 0;
            $matchedRecords = 0;
            $unmatchedRecords = 0;
            $reportRows = [];
            $now = now();

            foreach ($platformService->fetchDailyRecordPages($account, $startDate, $endDate, self::FETCH_PAGE_SIZE) as $records) {
                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $totalRecords++;

                    $row = $this->buildReportRow($account, $record, $projectCodeLookup, $now);
                    if ($row === null) {
                        $unmatchedRecords++;
                        continue;
                    }

                    $reportRows[$this->reportRowKey($row)] = $row;
                    $matchedRecords++;

                    if (count($reportRows) >= self::UPSERT_BATCH_SIZE) {
                        $this->upsertReports(array_values($reportRows));
                        $reportRows = [];
                    }
                }
            }

            $this->upsertReports(array_values($reportRows));

            $job->update([
                'status' => AdSpendSyncJob::STATUS_SUCCESS,
                'total_records' => $totalRecords,
                'matched_records' => $matchedRecords,
                'unmatched_records' => $unmatchedRecords,
                'error_message' => null,
            ]);

            $account->update(['last_sync_at' => now()]);

            Log::info('Ad spend sync finished', [
                'source' => $source,
                'job_id' => $job->id,
                'account_id' => $account->id,
                'platform_code' => $account->platform_code,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_records' => $totalRecords,
                'matched_records' => $matchedRecords,
                'unmatched_records' => $unmatchedRecords,
            ]);

            return $job->fresh();
        } catch (\Throwable $e) {
            $job->update([
                'status' => AdSpendSyncJob::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::error('Ad spend sync failed', [
                'source' => $source,
                'job_id' => $job->id,
                'account_id' => $account->id,
                'platform_code' => $account->platform_code,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Build a normalized report row or return null when the source record cannot be matched.
     */
    private function buildReportRow(AdSpendPlatformAccount $account, array $record, array $projectCodeLookup, $now): ?array
    {
        $rawGroupName = trim((string) ($record['groupName'] ?? $record['group_name'] ?? $record['groupId'] ?? $record['group_id'] ?? ''));
        $projectCode = $this->resolveProjectCode($rawGroupName, $projectCodeLookup);
        $reportDate = (string) ($record['date'] ?? '');

        if ($reportDate === '' || $projectCode === '') {
            return null;
        }

        $country = (string) ($record['country'] ?? '');
        if ($country === 'null') {
            $country = '';
        }

        return [
            'platform_account_id' => $account->id,
            'platform_code' => $account->platform_code,
            'project_code' => $projectCode,
            'report_date' => $reportDate,
            'country' => $country,
            'impressions' => (int) ($record['impressions'] ?? 0),
            'clicks' => (int) ($record['clicks'] ?? 0),
            'spend' => $this->toDecimal($record['spend'] ?? 0, true),
            'ctr' => $this->toDecimal($record['ctr'] ?? null, false),
            'cpm' => $this->toDecimal($record['cpm'] ?? null, false),
            'cpc' => $this->toDecimal($record['cpc'] ?? null, false),
            'raw_group_name' => $rawGroupName,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Build the uniqueness key used by the destination daily report table.
     */
    private function reportRowKey(array $row): string
    {
        return implode('|', [
            $row['platform_account_id'],
            $row['project_code'],
            $row['report_date'],
            $row['country'],
        ]);
    }

    private function loadProjectCodeLookup(): array
    {
        $lookup = DB::table('project_projects')
            ->pluck('project_code')
            ->mapWithKeys(function ($code) {
                $value = trim((string) $code);
                if ($value === '') {
                    return [];
                }

                return [strtoupper($value) => $value];
            })
            ->toArray();

        uksort($lookup, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $lookup;
    }

    /**
     * Write matched spend reports in bounded chunks to avoid oversized prepared statements.
     */
    private function upsertReports(array $rows): void
    {
        foreach (array_chunk($rows, self::UPSERT_BATCH_SIZE) as $chunk) {
            DB::table('ad_spend_platform_daily_reports')->upsert(
                $chunk,
                ['platform_account_id', 'project_code', 'report_date', 'country'],
                [
                    'platform_code',
                    'impressions',
                    'clicks',
                    'spend',
                    'ctr',
                    'cpm',
                    'cpc',
                    'raw_group_name',
                    'updated_at',
                ]
            );
        }
    }

    /**
     * Normalize numeric fields to match decimal column expectations.
     */
    private function toDecimal($value, bool $defaultZero)
    {
        if ($value === null || $value === '') {
            return $defaultZero ? 0 : null;
        }

        return is_numeric($value) ? (string) $value : ($defaultZero ? 0 : null);
    }

    /**
     * Match group name or group id fragments to a known project code.
     */
    private function resolveProjectCode(string $rawGroupName, array $projectCodeLookup): string
    {
        $raw = trim($rawGroupName);
        if ($raw === '' || empty($projectCodeLookup)) {
            return '';
        }

        $upperRaw = strtoupper($raw);
        if (isset($projectCodeLookup[$upperRaw])) {
            return $projectCodeLookup[$upperRaw];
        }

        foreach (array_keys($projectCodeLookup) as $upperCode) {
            $pattern = '/(^|[^A-Z0-9])' . preg_quote($upperCode, '/') . '([^A-Z0-9]|$)/';
            if (preg_match($pattern, $upperRaw) === 1) {
                return $projectCodeLookup[$upperCode];
            }
        }

        return '';
    }
}
