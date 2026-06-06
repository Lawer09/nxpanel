<?php

namespace App\Providers;

use App\Services\Plugin\HookManager;
use App\Services\UpdateService;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Facades\Octane;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Octane\Events\WorkerStarting;

class OctaneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }
        if ($this->app->bound('octane')) {
            $this->app['events']->listen(WorkerStarting::class, function () {
                app(UpdateService::class)->updateVersionCache();
                HookManager::reset();
            });
        }
        // 每半钟执行一次调度检查
        Octane::tick('scheduler', function () {
            $lock = Cache::lock('scheduler-lock', 30);

            if ($lock->get()) {
                try {
                    // Reset lingering DB state in the long-lived worker before running the scheduler.
                    $this->resetSchedulerDatabaseState();

                    Artisan::call('schedule:run');
                } finally {
                    $lock->release();
                }
            }
        })->seconds(30);
    }

    /**
     * Reset DB transaction state before running scheduled commands in Octane workers.
     */
    protected function resetSchedulerDatabaseState(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::purge();
        DB::reconnect();
    }
}
