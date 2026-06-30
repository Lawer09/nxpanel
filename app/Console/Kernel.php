<?php

namespace App\Console;

use App\Services\Plugin\PluginManager;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use App\Services\UserOnlineService;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // v2board
        $schedule->command('nxpanel:statistics')->dailyAt('0:10')->onOneServer();
        // check
        $schedule->command('check:order')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:commission')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:ticket')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('subscription:downgrade-expired-to-free')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('user:ban-inactive-zero-usage')->dailyAt('1:30')->onOneServer()->withoutOverlapping(30);
        // reset
        $schedule->command('reset:traffic')->everyMinute()->onOneServer()->withoutOverlapping(10);
        $schedule->command('reset:log')->daily()->onOneServer();
        // send
        $schedule->command('send:remindMail', ['--force'])->dailyAt('11:30')->onOneServer();
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
        // backup Timing
        // if (env('ENABLE_AUTO_BACKUP_AND_UPDATE', false)) {
        //     $schedule->command('backup:database', ['true'])->daily()->onOneServer();
        // }
        $schedule->command('cleanup:expired-online-status')->everyMinute()->onOneServer()->withoutOverlapping(4);

        // OSS 流量数据归档（每小时，归档上一小时数据）
        $schedule->command('stat:archive-to-oss')->hourly()->onOneServer()->withoutOverlapping(10);
        
        // 清理两周前的流量明细（每日凌晨 2:00）
        $schedule->command('stat:prune-detail')->dailyAt('2:00')->onOneServer()->withoutOverlapping(30);

        // 节点性能上报聚合（每 5 分钟）
        $schedule->command('perf:aggregate')->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);

        // 用户上报聚合（每 5 分钟，先归档再统计）
        $schedule->command('user_report:aggregate')->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);

        // 节点上报数据派发（每 5 分钟，先归档再投递队列）
        $schedule->command('node_server_report:dispatch')->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);

        // 统一小时报表聚合（每 5 分钟，重算当前小时和上一小时）
        $schedule->command('report_hourly:aggregate')->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);

        // 项目日报聚合（每 5 分钟刷新当天）
        $schedule->command('project:aggregate-daily')->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);
        $schedule->command('project:aggregate-hourly')->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);

        // 投放日报同步（每 10 分钟，同步最近 2 天）
        $schedule->command('ad-spend:sync --lookback-days=2')->everyTenMinutes()->onOneServer()->withoutOverlapping(55);

        // 应用客户端凭证同步到 Redis（每分钟）
        $schedule->command('app-client:sync-redis')->everyMinute()->onOneServer()->withoutOverlapping(2);

        // 自动化检测与告警（每 5 分钟触发一次，实际由 Horizon 消费 automation 队列执行）
        $schedule->command('automation:run traffic_platform')->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);
        $schedule->command('automation:run project_aggregate')->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);
        $schedule->command('automation:run project_ad_revenue_hourly')->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);

        // Firebase 事件汇总（每5分钟重算最近3天）
        $schedule->command('firebase_report:aggregate --hours=72')->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);

        app(PluginManager::class)->registerPluginSchedules($schedule);

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        try {
            app(PluginManager::class)->initializeEnabledPlugins();
        } catch (\Exception $e) {
        }
        require base_path('routes/console.php');
    }
}
