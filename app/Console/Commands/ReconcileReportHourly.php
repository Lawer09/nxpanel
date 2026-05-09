<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileReportHourly extends Command
{
    protected $signature = 'report_hourly:reconcile
        {date : YYYY-MM-DD}
        {--hour= : Optional hour 0-23}';

    protected $description = 'Reconcile source tables and hourly aggregate totals';

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
        if ($hourOpt !== null && (!is_numeric($hourOpt) || (int) $hourOpt < 0 || (int) $hourOpt > 23)) {
            $this->error('--hour must be 0-23');
            return self::FAILURE;
        }

        $hour = $hourOpt !== null ? (int) $hourOpt : null;

        $summary = [
            'user.report_count_user' => $this->sum('v3_user_report_user', 'compute_count', $date, $hour),
            'user.report_count_node' => $this->sum('v3_node_server_report_user', 'compute_count', $date, $hour),
            'user_hourly.report_count_user' => $this->sum('v3_report_user_hourly', 'report_count_user', $date, $hour),
            'user_hourly.report_count_node' => $this->sum('v3_report_user_hourly', 'report_count_node', $date, $hour),
            'node.report_count_user' => $this->sum('v3_user_report_node', 'compute_count', $date, $hour),
            'node.report_count_node' => $this->sum('v3_node_server_report_node', 'compute_count', $date, $hour),
            'node_hourly.report_count_user' => $this->sum('v3_report_node_hourly', 'report_count_user', $date, $hour),
            'node_hourly.report_count_node' => $this->sum('v3_report_node_hourly', 'report_count_node', $date, $hour),
        ];

        foreach ($summary as $name => $value) {
            $this->line(sprintf('%s = %s', $name, $value));
        }

        $ok = true;
        $ok = $ok && ((float) $summary['user.report_count_user'] === (float) $summary['user_hourly.report_count_user']);
        $ok = $ok && ((float) $summary['user.report_count_node'] === (float) $summary['user_hourly.report_count_node']);
        $ok = $ok && ((float) $summary['node.report_count_user'] === (float) $summary['node_hourly.report_count_user']);
        $ok = $ok && ((float) $summary['node.report_count_node'] === (float) $summary['node_hourly.report_count_node']);

        if ($ok) {
            $this->info('reconcile passed');
            return self::SUCCESS;
        }

        $this->warn('reconcile mismatch found');
        return self::FAILURE;
    }

    private function sum(string $table, string $column, string $date, ?int $hour)
    {
        $query = DB::table($table)->where('date', $date);
        if ($hour !== null) {
            $query->where('hour', $hour);
        }

        return (float) ($query->sum($column) ?? 0);
    }
}
