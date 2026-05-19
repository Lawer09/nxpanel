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
        $this->app->singleton(AutomationModuleRegistry::class, function ($app) {
            $registry = new AutomationModuleRegistry();
            $registry->registerHandlers([
                $app->make(TrafficPlatformAutomationService::class),
            ]);

            return $registry;
        });
    }
}
