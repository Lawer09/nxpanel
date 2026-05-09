<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class RebuildReportHourlyByDay extends Command
{
    protected $signature = 'report_hourly:rebuild
        {date : YYYY-MM-DD}
        {--hour= : Optional hour 0-23}
        {--keep-existing : Keep existing rows and upsert only}';

    protected $description = 'Rebuild hourly report tables by day or hour';

    public function handle(): int
    {
        $dateArg = (string) $this->argument('date');

        try {
            $date = Carbon::createFromFormat('Y-m-d', $dateArg)->toDateString();
        } catch (\Throwable $e) {
            $this->error('date 参数格式错误，请使用 YYYY-MM-DD');
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

        if (!$keepExisting) {
            DB::table('v3_report_user_hourly')->where('date', $date)->whereIn('hour', $hours)->delete();
            DB::table('v3_report_node_hourly')->where('date', $date)->whereIn('hour', $hours)->delete();
            $this->info('Deleted existing hourly rows for target range.');
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
        return self::SUCCESS;
    }
}
