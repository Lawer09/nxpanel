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
use App\Http\Controllers\V3\Admin\TicketController;
use App\Http\Controllers\V3\Admin\PerformanceController;
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
                $router->post('/bindeips', [ProviderController::class, 'bindElasticIp']);
                $router->post('/keypairs', [ProviderController::class, 'getKeyPairs']);
                $router->post('/zones', [ProviderController::class, 'getZones']);
                $router->post('/subnets', [ProviderController::class, 'getSubnets']);
                $router->post('/instance-types', [ProviderController::class, 'getInstanceTypes']);
                $router->post('/create-instance', [ProviderController::class, 'createInstance']);
            });

            // Machine
            $router->group([
                'prefix' => 'machine'
            ], function ($router) {
                $router->any('/fetch', [MachineController::class, 'fetch']);
                $router->post('/save', [MachineController::class, 'save']);
                $router->post('/update', [MachineController::class, 'update']);
                $router->post('/drop', [MachineController::class, 'drop']);
                $router->post('/detail', [MachineController::class, 'detail']);
                $router->post('/testConnection', [MachineController::class, 'testConnection']);
                $router->post('/batchDrop', [MachineController::class, 'batchDrop']);
                $router->post('/batchImport', [MachineController::class, 'batchImport']);
                $router->post('/deployNode', [MachineController::class, 'deployNode']);
                $router->post('/batchDeploy', [MachineController::class, 'batchDeploy']);
                $router->get('/deployStatus', [MachineController::class, 'deployStatus']);
                $router->post('/clearNode', [MachineController::class, 'clearNode']);
                $router->post('/createSimple', [MachineController::class, 'createSimple']);
                
                // Machine IP Management
                $router->post('/switchIp', [MachineIpController::class, 'switchIp']);
                $router->post('/configEipEgress', [MachineIpController::class, 'configEipEgress']);
                $router->get('/elasticIps', [MachineIpController::class, 'getElasticIps']);
                $router->post('/bindIp', [MachineIpController::class, 'bindIp']);
                $router->post('/unbindIp', [MachineIpController::class, 'unbindIp']);
                $router->post('/setPrimaryIp', [MachineIpController::class, 'setPrimaryIp']);
            });

            // Server Manage
            $router->group(['prefix' => 'server/manage'], function ($router) {
                $router->get('/onlineUsers',      [ManageController::class, 'getOnlineUsers']);
                $router->post('/batchBindDomain', [ManageController::class, 'batchBindDomain']);
                $router->post('/batchSave',       [ManageController::class, 'batchSave']);
                $router->get('/testPort',               [ManageController::class, 'testPort']);
                $router->post('/updateNodeConfig',      [ManageController::class, 'updateNodeConfig']);
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

            $router->group([
                'prefix' => 'ticket'
            ], function ($router) {
                $router->any('/fetch', [TicketController::class, 'fetch']);
                $router->post('/reply', [TicketController::class, 'reply']);
                $router->post('/close', [TicketController::class, 'close']);
            });

            // Performance
            $router->group(['prefix' => 'performance'], function ($router) {
                $router->get('/aggregated',      [PerformanceController::class, 'getAggregated']);
                $router->get('/userReportCount', [PerformanceController::class, 'getUserReportCount']);
                $router->get('/userReportDaily', [PerformanceController::class, 'getUserReportDaily']);
            });
        });
    }
}
