<?php
namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\User\OrderController;
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
        });
    }
}
