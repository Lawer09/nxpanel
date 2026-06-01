<?php

namespace App\Providers;

use App\Services\Automation\AutomationModuleRegistry;
use App\Services\Automation\ProjectAggregateAutomationService;
use App\Services\Automation\TrafficPlatformAutomationService;
use Illuminate\Support\ServiceProvider;

class AutomationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(AutomationModuleRegistry::class, function ($app) {
            return new AutomationModuleRegistry([
                $app->make(TrafficPlatformAutomationService::class),
                $app->make(ProjectAggregateAutomationService::class),
            ]);
        });
    }
}
