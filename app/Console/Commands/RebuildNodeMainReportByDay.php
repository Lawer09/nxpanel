<?php

namespace App\Console\Commands;

use App\Services\NodeMainReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildNodeMainReportByDay extends Command
{
    protected $signature = 'perf:rebuild-main-table-day
        {date : 目标日期，格式 YYYY-MM-DD}
        {--keep-existing : 不先删除当天已聚合数据}';

    protected $description = '按天重建节点主报表（按 5 分钟桶重算）';

    public function handle(NodeMainReportService $service): int
    {
        $dateArg = (string) $this->argument('date');

        try {
            $date = Carbon::createFromFormat('Y-m-d', $dateArg)->toDateString();
        } catch (\Throwable $e) {
            $this->error('date 参数格式错误，请使用 YYYY-MM-DD');
            return self::FAILURE;
        }

        if (!$this->option('keep-existing')) {
            $deleted = DB::table('v2_node_main_report_aggregated')->where('date', $date)->delete();
            $this->info("deleted existing rows: {$deleted}");
        }

        $count = 0;
        for ($hour = 0; $hour < 24; $hour++) {
            for ($minute = 0; $minute < 60; $minute += 5) {
                $service->aggregateByBucket($date, $hour, $minute);
                $count++;
            }
        }

        $this->info("rebuild finished: {$date}, buckets={$count}");

        return self::SUCCESS;
    }
}
