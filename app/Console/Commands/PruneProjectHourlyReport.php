<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneProjectHourlyReport extends Command
{
    protected $signature = 'project:prune-hourly
        {--days=30 : Keep recent N days of project hourly report data}
        {--chunk=1000 : Delete batch size}
        {--dry-run : Count rows without deleting}';

    protected $description = 'Prune old project_report_hourly rows and keep recent project hourly report data';

    /**
     * Delete project hourly report rows older than the configured retention window.
     */
    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunk = max(100, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoffDate = Carbon::today()->subDays($days)->toDateString();

        $query = DB::table('project_report_hourly')
            ->where('report_date', '<', $cutoffDate);

        $total = (clone $query)->count();
        $this->info(sprintf(
            '%s project_report_hourly rows before %s: %d',
            $dryRun ? '[DRY-RUN]' : 'Pruning',
            $cutoffDate,
            $total
        ));

        if ($dryRun || $total === 0) {
            return self::SUCCESS;
        }

        $deleted = 0;
        do {
            $ids = DB::table('project_report_hourly')
                ->where('report_date', '<', $cutoffDate)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('project_report_hourly')
                ->whereIn('id', $ids)
                ->delete();
        } while ($ids->count() === $chunk);

        Log::info('project:prune-hourly completed', [
            'days' => $days,
            'cutoff_date' => $cutoffDate,
            'deleted' => $deleted,
        ]);

        $this->info("Deleted {$deleted} project_report_hourly rows.");

        return self::SUCCESS;
    }
}
