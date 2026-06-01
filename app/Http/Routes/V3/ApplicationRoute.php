<?php

namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\Admin\AppController;
use App\Http\Controllers\V3\Admin\ReportController;
use Illuminate\Contracts\Routing\Registrar;

class ApplicationRoute
{
    /**
     * 应用侧路由（通过 app 鉴权访问部分 admin 能力）。
     */
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'application',
            'middleware' => ['app', 'log'],
        ], function ($router) {
            $router->group(['prefix' => 'app-client'], function ($router) {
                $router->get('/fetch',  [AppController::class, 'fetch']);
                $router->get('/detail', [AppController::class, 'detail']);
            });

            $router->group(['prefix' => 'report'], function ($router) {
                $router->post('/project/query', [ReportController::class, 'queryProjectReportHourly']);
            });
        });
    }
}
