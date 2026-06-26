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
            'size' => 200,
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
            $records = $platformService->fetchDailyRecords($account, $startDate, $endDate, 200);
            $projectCodeLookup = $this->loadProjectCodeLookup();

            $totalRecords = 0;
            $matchedRecords = 0;
            $unmatchedRecords = 0;
            $reportRows = [];
            $now = now();

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $totalRecords++;

                $rawGroupName = trim((string) ($record['groupName'] ?? $record['group_name'] ?? $record['groupId'] ?? $record['group_id'] ?? ''));
                $projectCode = $this->resolveProjectCode($rawGroupName, $projectCodeLookup);
                $reportDate = (string) ($record['date'] ?? '');

                if ($reportDate === '' || $projectCode === '') {
                    $unmatchedRecords++;
                    continue;
                }

                $country = (string) ($record['country'] ?? '');
                if ($country === 'null') {
                    $country = '';
                }

                $reportKey = implode('|', [$account->id, $projectCode, $reportDate, $country]);
                $reportRows[$reportKey] = [
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

                $matchedRecords++;
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

    private function loadProjectCodeLookup(): array
    {
        return DB::table('project_projects')
            ->pluck('project_code')
            ->mapWithKeys(function ($code) {
                $value = trim((string) $code);
                if ($value === '') {
                    return [];
                }

                return [strtoupper($value) => $value];
            })
            ->toArray();
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

        $keys = array_keys($projectCodeLookup);
        usort($keys, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($keys as $upperCode) {
            $pattern = '/(^|[^A-Z0-9])' . preg_quote($upperCode, '/') . '([^A-Z0-9]|$)/';
            if (preg_match($pattern, $upperRaw) === 1) {
                return $projectCodeLookup[$upperCode];
            }
        }

        return '';
    }
}
