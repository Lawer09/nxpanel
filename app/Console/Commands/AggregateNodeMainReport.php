<?php

namespace App\Console\Commands;

use App\Services\NodeMainReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AggregateNodeMainReport extends Command
{
    protected $signature = 'perf:aggregate-main-table
        {--date= : 指定日期，例如 2026-05-07}
        {--hour= : 指定小时 0-23}
        {--minute= : 指定分钟，会自动对齐到5分钟桶}';

    protected $description = '聚合节点主报表快照到 v2_node_main_report_aggregated';

    public function handle(NodeMainReportService $service): int
    {
        $date = $this->option('date');
        $hour = $this->option('hour');
        $minute = $this->option('minute');

        if ($date !== null && $hour !== null && $minute !== null) {
            $targetDate = (string) $date;
            $targetHour = (int) $hour;
            $targetMinute = (int) $minute;
        } else {
            $bucket = Carbon::now()->subMinutes(5);
            $targetDate = $bucket->toDateString();
            $targetHour = (int) $bucket->hour;
            $targetMinute = (int) $bucket->minute;
        }

        $targetMinute = (int) (floor($targetMinute / 5) * 5);

        $service->aggregateByBucket($targetDate, $targetHour, $targetMinute);

        $this->info(sprintf('node main report aggregated: %s %02d:%02d', $targetDate, $targetHour, $targetMinute));

        return self::SUCCESS;
    }
}
