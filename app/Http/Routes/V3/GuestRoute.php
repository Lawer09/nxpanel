<?php

namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\Guest\VersionController;
use Illuminate\Contracts\Routing\Registrar;

class GuestRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'guest',
        ], function ($router) {

            // Version Changelog
            $router->group(['prefix' => 'version'], function ($router) {
                $router->get('/list',   [VersionController::class, 'list']);
                $router->get('/latest', [VersionController::class, 'latest']);
                $router->get('/detail', [VersionController::class, 'detail']);
            });
        });
    }
}
