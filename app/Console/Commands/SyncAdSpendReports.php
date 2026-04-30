<?php

namespace App\Console\Commands;

use App\Models\AdSpendDailyReport;
use App\Models\AdSpendPlatformAccount;
use App\Models\AdSpendSyncJob;
use App\Services\AdSpendPlatformService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncAdSpendReports extends Command
{
    protected $signature = 'ad-spend:sync
        {--start-date= : 同步开始日期 (Y-m-d)}
        {--end-date= : 同步结束日期 (Y-m-d)}
        {--lookback-days=1 : 未传 start/end 时，默认回溯天数（含今天）}
        {--account-id=* : 指定账号 ID（可传多个）}';

    protected $description = '同步投放平台日报到 ad_spend_platform_daily_reports';

    public function handle(AdSpendPlatformService $service): int
    {
        [$startDate, $endDate] = $this->resolveDateRange();
        if (!$startDate || !$endDate) {
            return self::FAILURE;
        }

        $projectCodeLookup = DB::table('project_projects')
            ->pluck('project_code')
            ->mapWithKeys(function ($code) {
                $value = trim((string) $code);
                if ($value === '') {
                    return [];
                }
                return [strtoupper($value) => $value];
            })
            ->toArray();

        $accountIds = array_values(array_filter(array_map('intval', (array) $this->option('account-id'))));

        $accountsQuery = AdSpendPlatformAccount::query()->where('enabled', 1);
        if (!empty($accountIds)) {
            $accountsQuery->whereIn('id', $accountIds);
        }

        $accounts = $accountsQuery->orderBy('id')->get();
        if ($accounts->isEmpty()) {
            $this->warn('No enabled ad spend accounts found.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Start syncing ad spend reports: %s ~ %s, accounts=%d', $startDate, $endDate, $accounts->count()));

        $failedAccounts = 0;

        foreach ($accounts as $account) {
            $job = null;

            try {
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

                $records = $service->fetchDailyRecords($account, $startDate, $endDate, 200);
                $totalRecords = 0;
                $matchedRecords = 0;
                $unmatchedRecords = 0;

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

                    $impressions = (int) ($record['impressions'] ?? 0);
                    $clicks = (int) ($record['clicks'] ?? 0);
                    $spend = $this->toDecimal($record['spend'] ?? 0, true);
                    $ctr = $this->toDecimal($record['ctr'] ?? null, false);
                    $cpm = $this->toDecimal($record['cpm'] ?? null, false);
                    $cpc = $this->toDecimal($record['cpc'] ?? null, false);

                    AdSpendDailyReport::updateOrCreate(
                        [
                            'platform_account_id' => $account->id,
                            'project_code' => $projectCode,
                            'report_date' => $reportDate,
                            'country' => $country,
                        ],
                        [
                            'platform_code' => $account->platform_code,
                            'project_code' => $projectCode,
                            'impressions' => $impressions,
                            'clicks' => $clicks,
                            'spend' => $spend,
                            'ctr' => $ctr,
                            'cpm' => $cpm,
                            'cpc' => $cpc,
                            'raw_group_name' => $rawGroupName,
                        ]
                    );

                    $matchedRecords++;
                }

                $job->update([
                    'status' => AdSpendSyncJob::STATUS_SUCCESS,
                    'total_records' => $totalRecords,
                    'matched_records' => $matchedRecords,
                    'unmatched_records' => $unmatchedRecords,
                    'error_message' => null,
                ]);

                $account->update(['last_sync_at' => now()]);

                $this->info(sprintf(
                    'Account #%d synced. total=%d, matched=%d, unmatched=%d',
                    $account->id,
                    $totalRecords,
                    $matchedRecords,
                    $unmatchedRecords
                ));
            } catch (\Throwable $e) {
                $failedAccounts++;

                if ($job) {
                    $job->update([
                        'status' => AdSpendSyncJob::STATUS_FAILED,
                        'error_message' => mb_substr($e->getMessage(), 0, 2000),
                    ]);
                }

                Log::error('ad-spend:sync failed', [
                    'account_id' => $account->id,
                    'platform_code' => $account->platform_code,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'error' => $e->getMessage(),
                ]);

                $this->error(sprintf('Account #%d sync failed: %s', $account->id, $e->getMessage()));
            }
        }

        if ($failedAccounts > 0) {
            $this->warn(sprintf('Sync completed with failures: failed_accounts=%d', $failedAccounts));
            return self::FAILURE;
        }

        $this->info('Ad spend sync completed successfully.');
        return self::SUCCESS;
    }

    private function resolveDateRange(): array
    {
        $startOption = trim((string) $this->option('start-date'));
        $endOption = trim((string) $this->option('end-date'));

        try {
            if ($startOption !== '' || $endOption !== '') {
                $startDate = $startOption !== ''
                    ? Carbon::parse($startOption)->toDateString()
                    : Carbon::parse($endOption)->toDateString();
                $endDate = $endOption !== ''
                    ? Carbon::parse($endOption)->toDateString()
                    : Carbon::parse($startOption)->toDateString();
            } else {
                $lookbackDays = max(1, (int) $this->option('lookback-days'));
                $endDate = now()->toDateString();
                $startDate = now()->subDays($lookbackDays - 1)->toDateString();
            }

            if ($startDate > $endDate) {
                [$startDate, $endDate] = [$endDate, $startDate];
            }

            return [$startDate, $endDate];
        } catch (\Throwable $e) {
            $this->error('Invalid date option: ' . $e->getMessage());
            return [null, null];
        }
    }

    private function toDecimal($value, bool $defaultZero)
    {
        if ($value === null || $value === '') {
            return $defaultZero ? 0 : null;
        }

        return is_numeric($value) ? (string) $value : ($defaultZero ? 0 : null);
    }

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
