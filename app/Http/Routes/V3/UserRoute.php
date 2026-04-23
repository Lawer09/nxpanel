<?php
namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\User\OrderController;
use App\Http\Controllers\V3\User\PlanController;
use App\Http\Controllers\V3\User\IpInfoController;
use App\Http\Controllers\V3\User\UserController;
use App\Http\Controllers\V3\User\UserReportController;
use App\Http\Controllers\V3\User\TicketController;
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

            $router->get('/ipInfo', [IpInfoController::class, 'getClientIpInfo']);

            // Reporting
            $router->post('/performance/report', [UserReportController::class, 'report']);
            $router->post('/performance/batchReport', [UserReportController::class, 'batchReport']);
            $router->get('/performance/history', [UserReportController::class, 'getHistory']);
            $router->get('/performance/nodeStats', [UserReportController::class, 'getNodeStats']);

            $router->post('/ticket/reply', [TicketController::class, 'reply']);
            $router->post('/ticket/close', [TicketController::class, 'close']);
            $router->post('/ticket/save', [TicketController::class, 'save']);
            $router->get('/ticket/fetch', [TicketController::class, 'fetch']);
            $router->post('/ticket/withdraw', [TicketController::class, 'withdraw']);
        });
    }
}
