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
            'dims' => ['date', 'group_id', 'country', 'platform'],
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
     * Sync ad spend hourly reports for one account and one date range.
     */
    public function syncHourlyAccount(
        AdSpendPlatformAccount $account,
        string $startDate,
        string $endDate,
        AdSpendPlatformService $platformService,
        string $source = self::SOURCE_SCHEDULED
    ): AdSpendSyncJob {
        $requestParams = [
            'granularity' => 'hourly',
            'endpoint' => '/api/v2/report/group/hour/overall',
            'dims' => ['date', 'hour', 'group_name', 'group_id', 'country', 'platform'],
            'metrics' => ['impressions', 'clicks', 'spend', 'ctr', 'cpm', 'cpc', 'roas'],
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

        Log::info('Ad spend hourly sync started', [
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
            $remoteTotal = null;
            $pageCount = 0;

            foreach ($platformService->fetchHourlyRecordPagePayloads($account, $startDate, $endDate, self::FETCH_PAGE_SIZE) as $page) {
                $records = $page['records'] ?? [];
                $remoteTotal = $page['total'] ?? $remoteTotal;
                $pageCount = max($pageCount, (int) ($page['page'] ?? 0));

                foreach ($records as $record) {
                    if (!is_array($record)) {
                        continue;
                    }

                    $totalRecords++;

                    $row = $this->buildHourlyReportRow($account, $record, $projectCodeLookup, $now);
                    if ($row === null) {
                        $unmatchedRecords++;
                        continue;
                    }

                    $key = $this->hourlyReportRowKey($row);
                    $reportRows[$key] = isset($reportRows[$key])
                        ? $this->mergeHourlyReportRows($reportRows[$key], $row, $now)
                        : $row;
                    $matchedRecords++;

                    if (count($reportRows) >= self::UPSERT_BATCH_SIZE) {
                        $this->upsertHourlyReports(array_values($reportRows));
                        $reportRows = [];
                    }
                }
            }

            $this->upsertHourlyReports(array_values($reportRows));

            $job->update([
                'status' => AdSpendSyncJob::STATUS_SUCCESS,
                'total_records' => $totalRecords,
                'matched_records' => $matchedRecords,
                'unmatched_records' => $unmatchedRecords,
                'error_message' => null,
            ]);

            $account->update(['last_sync_at' => now()]);

            Log::info('Ad spend hourly sync finished', [
                'source' => $source,
                'job_id' => $job->id,
                'account_id' => $account->id,
                'platform_code' => $account->platform_code,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'total_records' => $totalRecords,
                'matched_records' => $matchedRecords,
                'unmatched_records' => $unmatchedRecords,
                'remote_total' => $remoteTotal,
                'pages' => $pageCount,
            ]);

            return $job->fresh();
        } catch (\Throwable $e) {
            $job->update([
                'status' => AdSpendSyncJob::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            Log::error('Ad spend hourly sync failed', [
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
            'platform' => $this->normalizeRemotePlatform($record),
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
     * Build a normalized hourly report row or return null when it cannot be matched.
     */
    private function buildHourlyReportRow(AdSpendPlatformAccount $account, array $record, array $projectCodeLookup, $now): ?array
    {
        $rawGroupName = trim((string) ($record['groupName'] ?? $record['group_name'] ?? $record['groupId'] ?? $record['group_id'] ?? ''));
        $groupKey = $rawGroupName !== '' ? $rawGroupName : trim((string) ($record['groupId'] ?? $record['group_id'] ?? ''));
        $projectCode = $this->resolveProjectCode($rawGroupName, $projectCodeLookup);
        $reportDate = (string) ($record['date'] ?? '');
        $hour = $record['hour'] ?? null;

        if ($reportDate === '' || $projectCode === '' || $groupKey === '' || $hour === null || $hour === '') {
            return null;
        }

        $hour = max(0, min(23, (int) $hour));
        $country = (string) ($record['country'] ?? $record['countryCode'] ?? 'XX');
        if ($country === '' || strtolower($country) === 'null') {
            $country = 'XX';
        }

        return [
            'platform_account_id' => $account->id,
            'platform_code' => $account->platform_code,
            'project_code' => $projectCode,
            'report_date' => $reportDate,
            'hour' => $hour,
            'country' => strtoupper($country),
            'object_id' => $record['objectId'] ?? $record['object_id'] ?? null,
            'group_id' => $record['groupId'] ?? $record['group_id'] ?? null,
            'raw_group_name' => $rawGroupName,
            'group_key' => $groupKey,
            'user_id' => $record['userId'] ?? $record['user_id'] ?? null,
            'agency_id' => $record['agencyId'] ?? $record['agency_id'] ?? null,
            'impressions' => (int) ($record['impressions'] ?? 0),
            'clicks' => (int) ($record['clicks'] ?? 0),
            'spend' => $this->toDecimal($record['spend'] ?? 0, true),
            'ctr' => $this->toDecimal($record['ctr'] ?? null, false),
            'cpm' => $this->toDecimal($record['cpm'] ?? null, false),
            'cpc' => $this->toDecimal($record['cpc'] ?? null, false),
            'roas' => $this->toDecimal($record['roas'] ?? null, false),
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
            $row['platform'],
        ]);
    }

    private function hourlyReportRowKey(array $row): string
    {
        return implode('|', [
            $row['platform_account_id'],
            $row['project_code'],
            $row['report_date'],
            $row['hour'],
            $row['country'],
            $row['group_key'],
        ]);
    }

    /**
     * Merge multiple source rows for the same hourly destination dimension.
     */
    private function mergeHourlyReportRows(array $base, array $row, $now): array
    {
        $base['impressions'] = (int) ($base['impressions'] ?? 0) + (int) ($row['impressions'] ?? 0);
        $base['clicks'] = (int) ($base['clicks'] ?? 0) + (int) ($row['clicks'] ?? 0);
        $base['spend'] = (string) ((float) ($base['spend'] ?? 0) + (float) ($row['spend'] ?? 0));
        $base['updated_at'] = $now;

        if (($base['raw_group_name'] ?? '') === '' && ($row['raw_group_name'] ?? '') !== '') {
            $base['raw_group_name'] = $row['raw_group_name'];
        }

        return $base;
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
            $chunk = $this->filterBlankPlatformDuplicateRows($chunk);
            if (empty($chunk)) {
                continue;
            }

            DB::transaction(function () use ($chunk) {
                $this->deleteBlankPlatformRowsForNonBlankRows($chunk);

                DB::table('ad_spend_platform_daily_reports')->upsert(
                    $chunk,
                    ['platform_account_id', 'project_code', 'report_date', 'country', 'platform'],
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
            });
        }
    }

    /**
     * Drop blank-platform rows when the same dimension already has a real platform.
     */
    private function filterBlankPlatformDuplicateRows(array $rows): array
    {
        $nonBlankKeys = [];
        $blankRows = [];

        foreach ($rows as $row) {
            if (($row['platform'] ?? '') !== '') {
                $nonBlankKeys[$this->platformAgnosticReportRowKey($row)] = true;
                continue;
            }

            $blankRows[] = $row;
        }

        if (empty($blankRows)) {
            return $rows;
        }

        $existingNonBlankKeys = $this->findExistingNonBlankPlatformKeys($blankRows);
        $filteredRows = [];
        foreach ($rows as $row) {
            if (($row['platform'] ?? '') !== '') {
                $filteredRows[] = $row;
                continue;
            }

            $key = $this->platformAgnosticReportRowKey($row);
            if (isset($nonBlankKeys[$key]) || isset($existingNonBlankKeys[$key])) {
                continue;
            }

            $filteredRows[] = $row;
        }

        return $filteredRows;
    }

    /**
     * Remove historical blank-platform rows before writing non-blank platform rows.
     */
    private function deleteBlankPlatformRowsForNonBlankRows(array $rows): void
    {
        $keys = [];
        foreach ($rows as $row) {
            if (($row['platform'] ?? '') === '') {
                continue;
            }

            $keys[$this->platformAgnosticReportRowKey($row)] = $row;
        }

        if (empty($keys)) {
            return;
        }

        DB::table('ad_spend_platform_daily_reports')
            ->where('platform', '=', '')
            ->where(function ($query) use ($keys) {
                foreach ($keys as $row) {
                    $query->orWhere(function ($subQuery) use ($row) {
                        $subQuery->where('platform_account_id', '=', $row['platform_account_id'])
                            ->where('project_code', '=', $row['project_code'])
                            ->where('report_date', '=', $row['report_date'])
                            ->where('country', '=', $row['country']);
                    });
                }
            })
            ->delete();
    }

    /**
     * Find dimensions that already have at least one non-blank platform row.
     */
    private function findExistingNonBlankPlatformKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[$this->platformAgnosticReportRowKey($row)] = $row;
        }

        if (empty($keys)) {
            return [];
        }

        $existingRows = DB::table('ad_spend_platform_daily_reports')
            ->where('platform', '!=', '')
            ->where(function ($query) use ($keys) {
                foreach ($keys as $row) {
                    $query->orWhere(function ($subQuery) use ($row) {
                        $subQuery->where('platform_account_id', '=', $row['platform_account_id'])
                            ->where('project_code', '=', $row['project_code'])
                            ->where('report_date', '=', $row['report_date'])
                            ->where('country', '=', $row['country']);
                    });
                }
            })
            ->get(['platform_account_id', 'project_code', 'report_date', 'country']);

        $existingKeys = [];
        foreach ($existingRows as $row) {
            $existingKeys[$this->platformAgnosticReportRowKey((array) $row)] = true;
        }

        return $existingKeys;
    }

    /**
     * Build a uniqueness key without the platform dimension for blank-platform deduplication.
     */
    private function platformAgnosticReportRowKey(array $row): string
    {
        return implode('|', [
            $row['platform_account_id'] ?? '',
            $row['project_code'] ?? '',
            $row['report_date'] ?? '',
            $row['country'] ?? '',
        ]);
    }

    /**
     * Normalize the remote platform dimension returned by the daily report API.
     */
    private function normalizeRemotePlatform(array $record): string
    {
        foreach (['platform', 'platform_name', 'devicePlatform', 'device_platform'] as $field) {
            if (!array_key_exists($field, $record)) {
                continue;
            }

            $platform = trim((string) $record[$field]);
            if ($platform !== '' && strtolower($platform) !== 'null') {
                return $platform;
            }
        }

        return '';
    }

    /**
     * Write matched hourly spend reports in bounded chunks.
     */
    private function upsertHourlyReports(array $rows): void
    {
        foreach (array_chunk($rows, self::UPSERT_BATCH_SIZE) as $chunk) {
            $chunk = array_map(fn (array $row) => $this->normalizeHourlyAggregateRatios($row), $chunk);

            DB::table('ad_spend_report_hourly')->upsert(
                $chunk,
                ['platform_account_id', 'project_code', 'report_date', 'hour', 'country', 'group_key'],
                [
                    'platform_code',
                    'object_id',
                    'group_id',
                    'raw_group_name',
                    'group_key',
                    'user_id',
                    'agency_id',
                    'impressions',
                    'clicks',
                    'spend',
                    'ctr',
                    'cpm',
                    'cpc',
                    'roas',
                    'updated_at',
                ]
            );
        }
    }

    /**
     * Recalculate derived hourly spend ratios after in-memory aggregation.
     */
    private function normalizeHourlyAggregateRatios(array $row): array
    {
        $impressions = (int) ($row['impressions'] ?? 0);
        $clicks = (int) ($row['clicks'] ?? 0);
        $spend = (float) ($row['spend'] ?? 0);

        $row['ctr'] = $impressions > 0 ? (string) round($clicks / $impressions * 100, 6) : null;
        $row['cpm'] = $impressions > 0 ? (string) round($spend / $impressions * 1000, 6) : null;
        $row['cpc'] = $clicks > 0 ? (string) round($spend / $clicks, 6) : null;

        return $row;
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
