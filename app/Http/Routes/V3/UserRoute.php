<?php
namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\User\OrderController;
use App\Http\Controllers\V3\User\PlanController;
use App\Http\Controllers\V3\User\UserController;
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
        });
    }
}
