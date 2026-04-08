<?php
namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\Client\ClientController;
use Illuminate\Contracts\Routing\Registrar;

class ClientRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'client',
            'middleware' => 'client'
        ], function ($router) {
            $router->get('/sub/json', [ClientController::class, 'subscribeJson']);
        });
    }
}
