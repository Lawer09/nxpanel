<?php

namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\Admin\OrderController;
use App\Http\Controllers\V3\Admin\PlanController;
use App\Http\Controllers\V3\Admin\ProviderController;
use App\Http\Controllers\V3\Admin\Server\ManageController;
use App\Http\Controllers\V3\Admin\StatController;
use App\Http\Controllers\V3\Admin\UserController;
use Illuminate\Contracts\Routing\Registrar;

class AdminRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix'     => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
            'middleware' => ['admin', 'log'],
        ], function ($router) {

            // Order
            $router->group(['prefix' => 'order'], function ($router) {
                $router->any('/fetch',   [OrderController::class, 'fetch']);
                $router->post('/update', [OrderController::class, 'update']);
                $router->post('/assign', [OrderController::class, 'assign']);
                $router->post('/paid',   [OrderController::class, 'paid']);
                $router->post('/cancel', [OrderController::class, 'cancel']);
                $router->post('/detail', [OrderController::class, 'detail']);
            });

            // Plan
            $router->group(['prefix' => 'plan'], function ($router) {
                $router->get('/fetch',    [PlanController::class, 'fetch']);
                $router->post('/save',    [PlanController::class, 'save']);
                $router->post('/drop',    [PlanController::class, 'drop']);
                $router->post('/update',  [PlanController::class, 'update']);
                $router->post('/sort',    [PlanController::class, 'sort']);
            });

            // User
            $router->group(['prefix' => 'user'], function ($router) {
                $router->any('/fetch',              [UserController::class, 'fetch']);
                $router->post('/update',            [UserController::class, 'update']);
                $router->get('/getUserInfoById',    [UserController::class, 'getUserInfoById']);
                $router->post('/generate',          [UserController::class, 'generate']);
                $router->post('/dumpCSV',           [UserController::class, 'dumpCSV']);
                $router->post('/sendMail',          [UserController::class, 'sendMail']);
                $router->post('/ban',               [UserController::class, 'ban']);
                $router->post('/resetSecret',       [UserController::class, 'resetSecret']);
                $router->post('/setInviteUser',     [UserController::class, 'setInviteUser']);
                $router->post('/destroy',           [UserController::class, 'destroy']);
            });

            // Provider
            $router->group(['prefix' => 'provider'], function ($router) {
                $router->post('/instances', [ProviderController::class, 'getInstances']);
            });

            // Server Manage
            $router->group(['prefix' => 'server/manage'], function ($router) {
                $router->get('/onlineUsers',      [ManageController::class, 'getOnlineUsers']);
                $router->post('/batchBindDomain', [ManageController::class, 'batchBindDomain']);
                $router->post('/batchSave',       [ManageController::class, 'batchSave']);
                $router->get('/testPort',               [ManageController::class, 'testPort']);
            });

            // Stat
            $router->group(['prefix' => 'stat'], function ($router) {
                $router->get('/getOverride',            [StatController::class, 'getOverride']);
                $router->get('/getStats',               [StatController::class, 'getStats']);
                $router->get('/getServerLastRank',      [StatController::class, 'getServerLastRank']);
                $router->get('/getServerYesterdayRank', [StatController::class, 'getServerYesterdayRank']);
                $router->any('/getStatUser',            [StatController::class, 'getStatUser']);
                $router->any('/getStatServer',          [StatController::class, 'getStatServer']);
                $router->get('/getStatServerDetail',    [StatController::class, 'getStatServerDetail']);
                $router->get('/getRanking',             [StatController::class, 'getRanking']);
                $router->get('/getTrafficRank',         [StatController::class, 'getTrafficRank']);
            });
        });
    }
}
