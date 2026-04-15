<?php

namespace App\Http\Controllers\V3\Client;

use App\Services\Plugin\HookManager;
use App\Services\UserService;
use Illuminate\Http\Request;
use App\Http\Controllers\V1\Client\ClientController as V1ClientController;
use App\Services\ServerService;
use App\Models\Server;
use App\Protocols\General;

class ClientController extends V1ClientController
{
    public function subscribeJson(Request $request)  
    {
        HookManager::call('client.subscribe.before');  
        $request->validate([  
            'types' => ['nullable', 'string'],  
            'filter' => ['nullable', 'string'],  
            'flag' => ['nullable', 'string'],  
        ]);
    
        $user = $request->user();
        $userService = new UserService();
    
        if (!$userService->isAvailable($user)) {
            HookManager::call('client.subscribe.unavailable');
            return $this->error([403, '用户不可用']);
        }
    
        // 过滤服务器
        $servers = ServerService::getAvailableServers($user);
        $servers = HookManager::filter('client.subscribe.servers', $servers, $user, $request);
    
        $requestedTypes = $this->parseRequestedTypes($request->input('types'));
        $filterKeywords = $this->parseFilterKeywords($request->input('filter'));
    
        $serversFiltered = $this->filterServers(
            servers: $servers,
            allowedTypes: $requestedTypes,
            filterKeywords: $filterKeywords
        );
    
        $serversFiltered = $this->addPrefixToServerName($serversFiltered);  
    
        // 为每个节点生成协议 URI，并按国家分组  
        $grouped = collect($serversFiltered)  
            ->map(function ($server) use ($user) {  
                $server['uri'] = $this->buildServerUri($server);  
                return $server;  
            })  
            ->groupBy(function ($server) {  
                return $this->extractCountry($server);  
            })  
            ->map(function ($nodes, $country) {  
                return $nodes->map(function ($server) {  
                    return [
                        'id' => $server['id'],
                        'name' => $server['name'],
                        'type' => $server['type'],
                        'host' => $server['host'],  
                        'port' => $server['port'],  
                        'uri'  => $server['uri'],  
                        // 保留与现有订阅格式一致的完整信息  
                    ];  
                })->values();  
            });  
    
        return $this->ok($grouped);  
    }  


    /**  
     * 从节点信息中提取国家标识  
     */  
    private function extractCountry(array $server): string  
    {  
        $name = $server['name'] ?? '';  
        $pos = strpos($name, '-');  
        if ($pos !== false && $pos > 0) {  
            return strtoupper(substr($name, 0, $pos));  
        }  
        return 'OTHER';  
    }


    /**  
     * 根据节点类型生成协议 URI
     */  
    private function buildServerUri(array $server): string  
    {  
        $password = $server['password'] ?? '';  
        return match ($server['type']) {  
            Server::TYPE_VMESS       => General::buildVmess($password, $server),  
            Server::TYPE_VLESS       => General::buildVless($password, $server),  
            Server::TYPE_SHADOWSOCKS => General::buildShadowsocks($password, $server),  
            Server::TYPE_TROJAN      => General::buildTrojan($password, $server),  
            Server::TYPE_HYSTERIA    => General::buildHysteria($password, $server),  
            Server::TYPE_ANYTLS      => General::buildAnyTLS($password, $server),  
            Server::TYPE_SOCKS       => General::buildSocks($password, $server),  
            Server::TYPE_TUIC        => General::buildTuic($password, $server),  
            Server::TYPE_HTTP        => General::buildHttp($password, $server),  
            default                  => '',  
        };  
    }
   
}
