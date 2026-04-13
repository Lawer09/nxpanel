<?php

namespace App\Http\Routes\V3;

use App\Http\Controllers\V3\Admin\OrderController;
use App\Http\Controllers\V3\Admin\PlanController;
use App\Http\Controllers\V3\Admin\ProviderController;
use App\Http\Controllers\V3\Admin\Server\ManageController;
use App\Http\Controllers\V3\Admin\StatController;
use App\Http\Controllers\V3\Admin\UserController;
use App\Http\Controllers\V3\Admin\MachineController;
use App\Http\Controllers\V3\Admin\MachineIpController;
use App\Http\Controllers\V3\Admin\IpPoolController;
use App\Http\Controllers\V3\Admin\SshKeyController;
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
                $router->post('/eips', [ProviderController::class, 'getElasticIps']);
                $router->post('/keypairs', [ProviderController::class, 'getKeyPairs']);
            });

            // Machine
            $router->group([
                'prefix' => 'machine'
            ], function ($router) {
                $router->any('/fetch', [\App\Http\Controllers\V3\Admin\MachineController::class, 'fetch']);
                $router->post('/save', [\App\Http\Controllers\V3\Admin\MachineController::class, 'save']);
                $router->post('/update', [\App\Http\Controllers\V3\Admin\MachineController::class, 'update']);
                $router->post('/drop', [\App\Http\Controllers\V3\Admin\MachineController::class, 'drop']);
                $router->post('/detail', [\App\Http\Controllers\V3\Admin\MachineController::class, 'detail']);
                $router->post('/testConnection', [\App\Http\Controllers\V3\Admin\MachineController::class, 'testConnection']);
                $router->post('/batchDrop', [\App\Http\Controllers\V3\Admin\MachineController::class, 'batchDrop']);
                $router->post('/batchImport', [\App\Http\Controllers\V3\Admin\MachineController::class, 'batchImport']);
                $router->post('/deployNode', [\App\Http\Controllers\V3\Admin\MachineController::class, 'deployNode']);
                $router->post('/batchDeploy', [\App\Http\Controllers\V3\Admin\MachineController::class, 'batchDeploy']);
                $router->get('/deployStatus', [\App\Http\Controllers\V3\Admin\MachineController::class, 'deployStatus']);
                $router->post('/clearNode', [\App\Http\Controllers\V3\Admin\MachineController::class, 'clearNode']);
                $router->post('/createSimple', [\App\Http\Controllers\V3\Admin\MachineController::class, 'createSimple']);
                
                // Machine IP Management
                $router->post('/switchIp', [\App\Http\Controllers\V3\Admin\MachineIpController::class, 'switchIp']);
                $router->post('/configEipEgress', [\App\Http\Controllers\V3\Admin\MachineIpController::class, 'configEipEgress']);
                $router->get('/elasticIps', [\App\Http\Controllers\V3\Admin\MachineIpController::class, 'getElasticIps']);
                $router->post('/bindIp', [\App\Http\Controllers\V3\Admin\MachineIpController::class, 'bindIp']);
                $router->post('/unbindIp', [\App\Http\Controllers\V3\Admin\MachineIpController::class, 'unbindIp']);
                $router->post('/setPrimaryIp', [\App\Http\Controllers\V3\Admin\MachineIpController::class, 'setPrimaryIp']);
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

             // IP Pool Management
            $router->group([
                'prefix' => 'ip-pool'
            ], function ($router) {
                $router->any('/fetch', [IpPoolController::class, 'fetch']);
                $router->post('/save', [IpPoolController::class, 'save']);
                $router->post('/detail', [IpPoolController::class, 'detail']);
                $router->post('/delete', [IpPoolController::class, 'delete']);
                $router->post('/enable', [IpPoolController::class, 'enable']);
                $router->post('/disable', [IpPoolController::class, 'disable']);
                $router->post('/reset-score', [IpPoolController::class, 'resetScore']);
                $router->get('/stats', [IpPoolController::class, 'stats']);
                $router->get('/get-ipinfo', [IpPoolController::class, 'getIpInfo']);
                $router->post('/batchImport', [IpPoolController::class, 'batchImport']);
                $router->post('/get-machines', [IpPoolController::class, 'getMachines']);
                $router->post('/get-active-machines', [IpPoolController::class, 'getActiveMachines']);
                $router->post('/get-primary-machine', [IpPoolController::class, 'getPrimaryMachine']);
                $router->post('/get-switchable-ips', [IpPoolController::class, 'getSwitchableIps']);
            });

            // SSH Key Management
            $router->group([
                'prefix' => 'ssh-key'
            ], function ($router) {
                $router->any('/fetch', [SshKeyController::class, 'fetch']);
                $router->post('/save', [SshKeyController::class, 'save']);
                $router->post('/update', [SshKeyController::class, 'update']);
                $router->post('/drop', [SshKeyController::class, 'drop']);
                $router->post('/detail', [SshKeyController::class, 'detail']);
                $router->post('/batchDrop', [SshKeyController::class, 'batchDrop']);
                $router->post('/batchImport', [SshKeyController::class, 'batchImport']);
            });
        });
    }
}
