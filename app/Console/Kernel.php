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

        // 项目日报聚合（每 5 分钟刷新当天）
        $schedule->command('project:aggregate-daily')->everyFiveMinutes()->onOneServer()->withoutOverlapping(4);

        // 应用客户端凭证同步到 Redis（每分钟）
        $schedule->command('app-client:sync-redis')->everyMinute()->onOneServer()->withoutOverlapping(2);

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
