<?php

namespace App\Providers;

use App\Services\Automation\AutomationModuleRegistry;
use App\Services\Automation\TrafficPlatformAutomationService;
use Illuminate\Support\ServiceProvider;

class AutomationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(AutomationModuleRegistry::class, fn () => new AutomationModuleRegistry());
    }

    /**
     * Bootstrap services.
     */
    public function boot(AutomationModuleRegistry $registry, TrafficPlatformAutomationService $trafficPlatformHandler): void
    {
        // 在 boot 阶段注册模块处理器，避免因容器初始化顺序导致 Registry 为空。
        $registry->registerHandler($trafficPlatformHandler);
    }
}
