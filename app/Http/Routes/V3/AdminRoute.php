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
use App\Http\Controllers\V3\Admin\VersionController;
use App\Http\Controllers\V3\Admin\AppController;
use App\Http\Controllers\V3\Admin\AppTrafficController;
use App\Http\Controllers\V3\Admin\AdAccountController;
use App\Http\Controllers\V3\Admin\AdSpendPlatformController;
use App\Http\Controllers\V3\Admin\ProjectAggregateController;
use App\Http\Controllers\V3\Admin\ProjectController;
use App\Http\Controllers\V3\Admin\ProjectTrafficAccountController;
use App\Http\Controllers\V3\Admin\ProjectAdAccountController;
use App\Http\Controllers\V3\Admin\ProjectMappingController;
use App\Http\Controllers\V3\Admin\SyncServerController;
use App\Http\Controllers\V3\Admin\SyncMonitorController;
use App\Http\Controllers\V3\Admin\AdRevenueController;
use App\Http\Controllers\V3\Admin\EnumController;
use App\Http\Controllers\V3\Admin\UserReportController;
use App\Http\Controllers\V3\Admin\TrafficPlatformController;
use App\Http\Controllers\V3\Admin\TrafficPlatformAccountController;
use App\Http\Controllers\V3\Admin\TrafficPlatformUsageController;
use App\Http\Controllers\V3\Admin\TrafficPlatformSyncController;
use Illuminate\Contracts\Routing\Registrar;


class AdminRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix'     => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))),
            'middleware' => ['admin', 'log'],
        ], function ($router) {

            // Enum Options
            $router->get('/enum/options', [EnumController::class, 'getOptions']);

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
                 $router->get('/getNodes', [ManageController::class, 'getNodes']);
                 $router->get('/history',   [ManageController::class, 'getHistory']);
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

                // App Traffic Report (按 register_metadata 中 app_id / app_version 统计流量)
                $router->group(['prefix' => 'appTraffic'], function ($router) {
                    $router->post('/aggregate', [AppTrafficController::class, 'aggregate']);
                    $router->post('/trend',     [AppTrafficController::class, 'trend']);
                    $router->post('/summary',   [AppTrafficController::class, 'summary']);
                });
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
            $router->group([
                'prefix' => 'performance', 
                'middleware' => ['duration']
            ], function ($router) {
                $router->get('/aggregated',            [PerformanceController::class, 'getAggregated']);
                $router->get('/userReportCount',       [PerformanceController::class, 'getUserReportCount']);
                $router->get('/userReportDaily',       [PerformanceController::class, 'getUserReportDaily']);
                $router->get('/versionDistribution',   [PerformanceController::class, 'getVersionDistribution']);
                $router->get('/platformDistribution',  [PerformanceController::class, 'getPlatformDistribution']);
                $router->get('/countryDistribution',   [PerformanceController::class, 'getCountryDistribution']);
                $router->get('/failedNodes',           [PerformanceController::class, 'getFailedNodes']);
                $router->get('/retention',             [PerformanceController::class, 'getRetention']);
                $router->get('/activeUsers',           [PerformanceController::class, 'getActiveUsers']);
                $router->get('/activeUsersSummary',    [PerformanceController::class, 'getActiveUsersSummary']);
                $router->get('/userHourlyStats',       [PerformanceController::class, 'getUserHourlyStats']);
                $router->get('/userGrowth',            [PerformanceController::class, 'getUserGrowth']);
            });

            // Realtime User Reports
            $router->group(['prefix' => 'userReport'], function ($router) {
                $router->get('/realtime', [UserReportController::class, 'getRealtime']);
            });

            // Version Changelog Management
            $router->group(['prefix' => 'version'], function ($router) {
                $router->get('/fetch',    [VersionController::class, 'fetch']);
                $router->post('/save',    [VersionController::class, 'save']);
                $router->post('/update',  [VersionController::class, 'update']);
                $router->post('/drop',    [VersionController::class, 'drop']);
                $router->get('/detail',   [VersionController::class, 'detail']);
                $router->post('/publish', [VersionController::class, 'publish']);
            });

            // App Client Management
            $router->group(['prefix' => 'app-client'], function ($router) {
                $router->get('/fetch',        [AppController::class, 'fetch']);
                $router->post('/save',        [AppController::class, 'save']);
                $router->post('/update',      [AppController::class, 'update']);
                $router->post('/drop',        [AppController::class, 'drop']);
                $router->get('/detail',       [AppController::class, 'detail']);
                $router->post('/resetSecret', [AppController::class, 'resetSecret']);
            });

            // Ad Platform Accounts
            $router->group([
                'prefix' => 'ad-accounts',
                'middleware' => ['duration']
            ], function ($router) {
                $router->get('/',                    [AdAccountController::class, 'fetch']);
                $router->post('/',                   [AdAccountController::class, 'save']);
                $router->put('/{id}',                [AdAccountController::class, 'update']);
                $router->patch('/{id}/status',       [AdAccountController::class, 'updateStatus']);
                $router->post('/{id}/test-credential', [AdAccountController::class, 'testCredential']);
                $router->post('/batch-assign-server', [AdAccountController::class, 'batchAssignServer']);
            });

            // Ad Spend Platform
            $router->group(['prefix' => 'ad-spend-platform'], function ($router) {
                $router->group(['prefix' => 'accounts'], function ($router) {
                    $router->get('/',                [AdSpendPlatformController::class, 'fetchAccounts']);
                    $router->get('/{id}',            [AdSpendPlatformController::class, 'detailAccount']);
                    $router->post('/',               [AdSpendPlatformController::class, 'saveAccount']);
                    $router->put('/{id}',            [AdSpendPlatformController::class, 'updateAccount']);
                    $router->patch('/{id}/status',   [AdSpendPlatformController::class, 'updateAccountStatus']);
                    $router->post('/{id}/test',      [AdSpendPlatformController::class, 'testAccount']);
                });

                $router->post('/sync',               [AdSpendPlatformController::class, 'sync']);
                $router->get('/sync-jobs',           [AdSpendPlatformController::class, 'fetchSyncJobs']);
                $router->get('/sync-jobs/{id}',      [AdSpendPlatformController::class, 'detailSyncJob']);

                $router->group(['prefix' => 'reports'], function ($router) {
                    $router->get('/daily',           [AdSpendPlatformController::class, 'daily']);
                    $router->get('/summary',         [AdSpendPlatformController::class, 'summary']);
                    $router->get('/trend',           [AdSpendPlatformController::class, 'trend']);
                });

                $router->get('/project-codes',       [AdSpendPlatformController::class, 'projectCodes']);
            });

            // Project Aggregates
            $router->group(['prefix' => 'project-aggregates'], function ($router) {
                $router->post('/aggregate', [ProjectAggregateController::class, 'aggregate']);
                $router->post('/aggregate-async', [ProjectAggregateController::class, 'aggregateAsync']);
                $router->get('/daily',   [ProjectAggregateController::class, 'daily']);
                $router->get('/summary', [ProjectAggregateController::class, 'summary']);
                $router->get('/trend',   [ProjectAggregateController::class, 'trend']);
            });
    
            // Project App Mappings
            $router->group(['prefix' => 'project-app-mappings'], function ($router) {
                $router->get('/',              [ProjectMappingController::class, 'fetch']);
                $router->post('/',             [ProjectMappingController::class, 'save']);
                $router->put('/{id}',          [ProjectMappingController::class, 'update']);
                $router->patch('/{id}/status', [ProjectMappingController::class, 'updateStatus']);
            });

            // Project Management
            $router->group(['prefix' => 'projects'], function ($router) {
                $router->get('/',               [ProjectController::class, 'fetch']);
                $router->get('/{id}',           [ProjectController::class, 'detail']);
                $router->post('/',              [ProjectController::class, 'save']);
                $router->put('/{id}',           [ProjectController::class, 'update']);
                $router->patch('/{id}/status',  [ProjectController::class, 'updateStatus']);

                $router->get('/{id}/traffic-accounts',                    [ProjectTrafficAccountController::class, 'fetch']);
                $router->post('/{id}/traffic-accounts',                   [ProjectTrafficAccountController::class, 'save']);
                $router->put('/{id}/traffic-accounts/{relationId}',       [ProjectTrafficAccountController::class, 'update']);
                $router->delete('/{id}/traffic-accounts/{relationId}',    [ProjectTrafficAccountController::class, 'drop']);

                $router->get('/{id}/ad-accounts',                         [ProjectAdAccountController::class, 'fetch']);
                $router->post('/{id}/ad-accounts',                        [ProjectAdAccountController::class, 'save']);
                $router->put('/{id}/ad-accounts/{relationId}',            [ProjectAdAccountController::class, 'update']);
                $router->delete('/{id}/ad-accounts/{relationId}',         [ProjectAdAccountController::class, 'drop']);

                $router->get('/{projectCode}/ad-spend-daily',              [AdSpendPlatformController::class, 'projectDaily']);
            });

            // Sync Servers
            $router->group(['prefix' => 'sync-servers'], function ($router) {
                $router->get('/',                    [SyncServerController::class, 'fetch']);
                $router->post('/',                   [SyncServerController::class, 'save']);
                $router->put('/{server_id}',         [SyncServerController::class, 'update']);
                $router->patch('/{server_id}/status', [SyncServerController::class, 'updateStatus']);
                $router->post('/{server_id}/test-sync', [SyncServerController::class, 'testSync']);
                $router->post('/{server_id}/sync-revenue', [SyncServerController::class, 'syncRevenueByDate']);
            });

            // Ad Revenue Report
            $router->group(['prefix' => 'ad-revenue'], function ($router) {
                $router->get('/fetch',     [AdRevenueController::class, 'fetch']);
                $router->post('/aggregate', [AdRevenueController::class, 'aggregate']);
                $router->get('/trend',     [AdRevenueController::class, 'trend']);
                $router->get('/summary',   [AdRevenueController::class, 'summary']);
                $router->post('/top-rank', [AdRevenueController::class, 'topRank']);
            });

            // Sync Monitor
            $router->get('/sync-states',  [SyncMonitorController::class, 'states']);
            $router->get('/sync-logs',    [SyncMonitorController::class, 'logs']);
            $router->post('/sync-jobs/trigger', [SyncMonitorController::class, 'trigger']);

            // Traffic Platform - 三方流量平台
            $router->group(['prefix' => 'traffic-platform'], function ($router) {

                // 平台配置
                $router->group(['prefix' => 'platforms'], function ($router) {
                    $router->get('/',              [TrafficPlatformController::class, 'fetch']);
                    $router->post('/',             [TrafficPlatformController::class, 'save']);
                    $router->put('/{id}',          [TrafficPlatformController::class, 'update']);
                    $router->patch('/{id}/status', [TrafficPlatformController::class, 'updateStatus']);
                });

                // 平台账号
                $router->group(['prefix' => 'accounts'], function ($router) {
                    $router->get('/',              [TrafficPlatformAccountController::class, 'fetch']);
                    $router->get('/{id}',          [TrafficPlatformAccountController::class, 'detail']);
                    $router->post('/',             [TrafficPlatformAccountController::class, 'save']);
                    $router->put('/{id}',          [TrafficPlatformAccountController::class, 'update']);
                    $router->patch('/{id}/status', [TrafficPlatformAccountController::class, 'updateStatus']);
                    $router->post('/{id}/test',    [TrafficPlatformAccountController::class, 'test']);
                });

                // 流量查询
                $router->group(['prefix' => 'usages'], function ($router) {
                    $router->get('/hourly',  [TrafficPlatformUsageController::class, 'hourly']);
                    $router->get('/daily',   [TrafficPlatformUsageController::class, 'daily']);
                    $router->get('/monthly', [TrafficPlatformUsageController::class, 'monthly']);
                    $router->get('/trend',   [TrafficPlatformUsageController::class, 'trend']);
                    $router->get('/ranking', [TrafficPlatformUsageController::class, 'ranking']);
                });

                // 同步
                $router->post('/sync',           [TrafficPlatformSyncController::class, 'trigger']);
                $router->get('/sync-jobs',       [TrafficPlatformSyncController::class, 'fetch']);
                $router->get('/sync-jobs/{id}',  [TrafficPlatformSyncController::class, 'detail']);
            });
        });
    }
}
