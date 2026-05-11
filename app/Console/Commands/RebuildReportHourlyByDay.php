<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class RebuildReportHourlyByDay extends Command
{
    protected $signature = 'report_hourly:rebuild
        {date : YYYY-MM-DD start date}
        {--to= : Optional end date YYYY-MM-DD (default: same as date)}
        {--hour= : Optional hour 0-23}
        {--keep-existing : Keep existing rows and upsert only}';

    protected $description = 'Rebuild hourly report tables by day or hour';

    public function handle(): int
    {
        $dateFrom = (string) $this->argument('date');
        $dateTo = $this->option('to') ?? $dateFrom;

        try {
            $start = Carbon::createFromFormat('Y-m-d', $dateFrom);
            $end = Carbon::createFromFormat('Y-m-d', $dateTo);
        } catch (\Throwable $e) {
            $this->error('date/--to 格式错误，请使用 YYYY-MM-DD');
            return self::FAILURE;
        }

        if ($start->gt($end)) {
            $this->error('date 不能晚于 --to');
            return self::FAILURE;
        }

        $hourOpt = $this->option('hour');
        $hours = [];
        if ($hourOpt !== null) {
            if (!is_numeric($hourOpt) || (int) $hourOpt < 0 || (int) $hourOpt > 23) {
                $this->error('--hour must be 0-23');
                return self::FAILURE;
            }
            $hours[] = (int) $hourOpt;
        } else {
            for ($i = 0; $i < 24; $i++) {
                $hours[] = $i;
            }
        }

        $keepExisting = (bool) $this->option('keep-existing');

        $current = clone $start;
        while ($current->lte($end)) {
            $date = $current->toDateString();

            if (!$keepExisting) {
                DB::table('v3_report_user_hourly')->where('date', $date)->whereIn('hour', $hours)->delete();
                DB::table('v3_report_node_hourly')->where('date', $date)->whereIn('hour', $hours)->delete();
                $this->line("Cleared hourly rows for: {$date}");
            }

            foreach ($hours as $hour) {
                $exitCode = Artisan::call('report_hourly:aggregate', [
                    '--date' => $date,
                    '--hour' => $hour,
                ]);

                $output = trim(Artisan::output());
                if ($output !== '') {
                    $this->line($output);
                }

                if ($exitCode !== self::SUCCESS) {
                    $this->error(sprintf('aggregate failed at %s %02d', $date, $hour));
                    return self::FAILURE;
                }
            }

            $this->info(sprintf('rebuild finished: %s hours=%d', $date, count($hours)));
            $current->addDay();
        }

        return self::SUCCESS;
    }
}
