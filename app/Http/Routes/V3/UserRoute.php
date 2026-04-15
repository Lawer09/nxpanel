<?php
namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\User\OrderController;
use App\Http\Controllers\V3\User\PlanController;
use App\Http\Controllers\V3\User\UserController;
use App\Http\Controllers\V3\User\PerformanceController;
use Illuminate\Contracts\Routing\Registrar;

class UserRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'user',
            'middleware' => 'user'
        ], function ($router) {
            $router->post('/order/externalPayment', [OrderController::class, 'externalPayment']);
            // Plan
            $router->get('/plan/fetch', [PlanController::class, 'fetch']);
            // sub
            $router->get('/getSubscribe', [UserController::class, 'getSubscribe']);

            // Performance Reporting (新增)
            $router->post('/performance/report', [PerformanceController::class, 'report']);
            $router->post('/performance/batchReport', [PerformanceController::class, 'batchReport']);
            $router->get('/performance/clientIpInfo', [PerformanceController::class, 'getClientIpInfo']);
            $router->get('/performance/history', [PerformanceController::class, 'getHistory']);
            $router->get('/performance/nodeStats', [PerformanceController::class, 'getNodeStats']);
        });
    }
}
