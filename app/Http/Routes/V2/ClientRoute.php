<?php
namespace App\Http\Routes\V2;

use App\Http\Controllers\V1\Client\AppController;
use Illuminate\Contracts\Routing\Registrar;
use App\Http\Controllers\V2\Client\PerformanceController;

class ClientRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'client',
            'middleware' => 'client'
        ], function ($router) {
            // App
            $router->get('/app/getConfig', [AppController::class, 'getConfig']);
            $router->get('/app/getVersion', [AppController::class, 'getVersion']);
        });

         // Performance Reporting (新增)
            $router->post('/performance/report', [PerformanceController::class, 'report']);
            $router->post('/performance/batchReport', [PerformanceController::class, 'batchReport']);
            $router->get('/performance/clientIpInfo', [PerformanceController::class, 'getClientIpInfo']);
            $router->get('/performance/history', [PerformanceController::class, 'getHistory']);
            $router->get('/performance/nodeStats', [PerformanceController::class, 'getNodeStats']);
    }
}
