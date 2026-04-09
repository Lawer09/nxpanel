<?php

namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\Passport\AuthController;
use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport',
        ], function ($router) {
            // Auth
            $router->post('/auth/register', [AuthController::class, 'register']);
            $router->post('/auth/login', [AuthController::class, 'login']);
            $router->post('/auth/loginByAid', [AuthController::class, 'loginByAid']);
        });
    }
}
