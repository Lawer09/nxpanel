<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneAdSpendHourlyReport extends Command
{
    protected $signature = 'ad-spend:prune-hourly
        {--days=30 : Keep recent N days of hourly ad spend report data}
        {--chunk=1000 : Delete batch size}
        {--dry-run : Count rows without deleting}';

    protected $description = 'Prune old ad_spend_report_hourly rows and keep recent hourly spend data';

    /**
     * Delete hourly ad spend rows older than the configured retention window.
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunk = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoffDate = Carbon::today()->subDays($days)->toDateString();

        $query = DB::table('ad_spend_report_hourly')
            ->where('report_date', '<', $cutoffDate);

        $total = (clone $query)->count();
        $this->info(sprintf(
            '%s ad_spend_report_hourly rows before %s: %d',
            $dryRun ? '[DRY-RUN]' : 'Pruning',
            $cutoffDate,
            $total
        ));

        if ($dryRun || $total === 0) {
            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $ids = DB::table('ad_spend_report_hourly')
                ->where('report_date', '<', $cutoffDate)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('ad_spend_report_hourly')
                ->whereIn('id', $ids)
                ->delete();
        } while ($ids->count() === $chunk);

        Log::info('ad-spend:prune-hourly completed', [
            'days' => $days,
            'cutoff_date' => $cutoffDate,
            'deleted' => $deleted,
        ]);

        $this->info("Deleted {$deleted} ad_spend_report_hourly rows.");

        return self::SUCCESS;
    }
}
