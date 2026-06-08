<?php

namespace App\Http\Routes\V3;

use App\Http\Controllers\Postback\PostbackController;
use Illuminate\Contracts\Routing\Registrar;

class PbRoute
{
    public function map(Registrar $router): void
    {
        $router->group([
            'prefix' => 'pb',
        ], function ($router) {
            $router->get('/{packageName}', [PostbackController::class, 'store'])
                ->where('packageName', '[A-Za-z0-9._-]+');
        });
    }
}
