<?php

namespace App\Console\Commands;

use App\Models\AdSpendPlatformAccount;
use App\Services\AdSpendPlatformService;
use App\Services\AdSpendSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncAdSpendHourlyReports extends Command
{
    private const ACCOUNT_CHUNK_SIZE = 50;

    protected $signature = 'ad-spend:sync-hourly
        {--start-date= : 同步开始日期 (Y-m-d)}
        {--end-date= : 同步结束日期 (Y-m-d)}
        {--lookback-days=2 : 未传 start/end 时，默认回溯天数（含今天）}
        {--account-id=* : 指定账号 ID（可传多个）}';

    protected $description = '同步投放平台小时报表到 ad_spend_report_hourly';

    /**
     * Sync hourly ad spend reports for enabled accounts.
     */
    public function handle(
        AdSpendPlatformService $platformService,
        AdSpendSyncService $syncService
    ): int {
        [$startDate, $endDate] = $this->resolveDateRange();
        if (!$startDate || !$endDate) {
            return self::FAILURE;
        }

        $accountIds = array_values(array_filter(array_map('intval', (array) $this->option('account-id'))));

        $accountsQuery = AdSpendPlatformAccount::query()->where('enabled', 1);
        if (!empty($accountIds)) {
            $accountsQuery->whereIn('id', $accountIds);
        }

        $accountCount = (clone $accountsQuery)->count();
        if ($accountCount === 0) {
            $this->warn('No enabled ad spend accounts found.');
            return self::SUCCESS;
        }

        $this->info(sprintf('Start syncing hourly ad spend reports: %s ~ %s, accounts=%d', $startDate, $endDate, $accountCount));

        $failedAccounts = 0;

        $accountsQuery->chunkById(self::ACCOUNT_CHUNK_SIZE, function ($accounts) use (
            $platformService,
            $syncService,
            $startDate,
            $endDate,
            &$failedAccounts
        ) {
            foreach ($accounts as $account) {
                try {
                    $job = $syncService->syncHourlyAccount(
                        $account,
                        $startDate,
                        $endDate,
                        $platformService,
                        AdSpendSyncService::SOURCE_SCHEDULED
                    );

                    $this->info(sprintf(
                        'Account #%d hourly synced. total=%d, matched=%d, unmatched=%d',
                        $account->id,
                        (int) $job->total_records,
                        (int) $job->matched_records,
                        (int) $job->unmatched_records
                    ));
                } catch (\Throwable $e) {
                    $failedAccounts++;
                    $this->error(sprintf('Account #%d hourly sync failed: %s', $account->id, $e->getMessage()));
                }
            }
        });

        if ($failedAccounts > 0) {
            $this->warn(sprintf('Hourly sync completed with failures: failed_accounts=%d', $failedAccounts));
            return self::FAILURE;
        }

        $this->info('Hourly ad spend sync completed successfully.');
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
}
