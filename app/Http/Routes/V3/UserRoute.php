<?php
namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\User\OrderController;
use App\Http\Controllers\V3\User\PlanController;
use App\Http\Controllers\V3\User\UserController;
use App\Http\Controllers\V3\User\UserReportController;
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

            // Reporting
            $router->post('/performance/report', [UserReportController::class, 'report']);
            $router->post('/performance/batchReport', [UserReportController::class, 'batchReport']);
            $router->get('/performance/clientIpInfo', [UserReportController::class, 'getClientIpInfo']);
            $router->get('/performance/history', [UserReportController::class, 'getHistory']);
            $router->get('/performance/nodeStats', [UserReportController::class, 'getNodeStats']);
        });
    }
}
