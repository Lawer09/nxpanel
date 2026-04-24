<?php

namespace App\Http\Controllers\V3\Admin\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController as V2ManageController;
use App\Models\Machine;
use App\Models\Server;
use App\Models\ServerTemplate;
use App\Models\User;
use App\Services\DnsToolService;
use App\Utils\CacheKey;
use App\Services\RemoteScriptService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\ServerService;
use App\Models\ServerGroup;

class ManageController extends V2ManageController
{
    public function getNodes(Request $request)
    {
        $request->validate([
            'page'     => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ]);

        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('pageSize', 20);

        $paginator = ServerService::getPagedServers($pageSize, $page);

        $items = $paginator->getCollection()->map(function ($item) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            return $item;
        });

        return $this->ok([
            'data'     => $items,
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ]);
    }
    /**
     * 查看节点当前在线用户（v3）
     *
     * GET /admin/server/manage/onlineUsers?id={serverId}
     *
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getOnlineUsers(Request $request): JsonResponse  
    {  
        $request->validate([  
            'id' => 'required|integer',  
        ]);  
    
        $server = Server::find($request->input('id'));  
        if (!$server) {  
            return $this->error([400202, '节点不存在']);  
        }  
    
        // nodeKey 格式必须与 UserAliveSyncJob 一致：小写 type + id  
        $nodeKey = $server->type . $server->id;  
    
        $users = User::query()  
            ->where('online_count', '>', 0)  
            ->select(['id', 'email', 'online_count', 'last_online_at'])  
            ->get();  
    
        $onlineUsers = [];  
    
        foreach ($users as $user) {  
            // 读取 ALIVE_IP_USER_{userId}，而非 USER_ONLINE_CONN_*  
            $cacheData = Cache::get('ALIVE_IP_USER_' . $user->id, []);  
    
            if (!isset($cacheData[$nodeKey]) || !is_array($cacheData[$nodeKey])) {  
                continue;  
            }  
    
            $nodeData = $cacheData[$nodeKey];  
            $aliveIps = $nodeData['aliveips'] ?? [];  
            $lastUpdateAt = $nodeData['lastupdateAt'] ?? null;  
    
            $ips = collect($aliveIps)  
                ->map(fn($ipNode) => explode('_', $ipNode)[0])  
                ->unique()  
                ->values()  
                ->all();  
    
            if (empty($ips)) {  
                continue;  
            }  
    
            $onlineUsers[] = [  
                'user_id'        => $user->id,  
                'email'          => $user->email,  
                'ip_count'       => count($ips),  
                'ips'            => $ips,  
                'last_update_at' => $lastUpdateAt  
                    ? date('Y-m-d H:i:s', $lastUpdateAt)  
                    : null,  
            ];  
        }  
    
        return $this->ok([  
            'server_id'    => $server->id,  
            'server_name'  => $server->name,  
            'node_key'     => $nodeKey,  
            'online_count' => count($onlineUsers),  
            'users'        => $onlineUsers,  
        ]);  
    }

    /**
    * 批量为节点绑定域名（v3）
    *
    * POST /admin/server/manage/batchBindDomain
    *
    * Body params:
    *   bindings  array     required  绑定列表，每项包含：
    *     - id        integer   required  节点 ID
    *     - domain    string   required  主域名（如 example.com）
    *     - subdomain string   nullable  子域名前缀（为空则直接解析到主域名）
    *     - unique    boolean  optional  是否唯一解析，默认 false
    *
    * Response (ok):
    *   results  array  成功列表，每项包含：
    *     - index        integer
    *     - server_id    integer
    *     - machine_id   integer
    *     - domain       string
    *     - subdomain    string|null
    *     - unique       boolean
    *     - resolved_ip  string
    *     - fqdn         string
    *     - updated_host string
    *     - success      boolean
    *   errors   array  失败列表，每项包含：
    *     - index     integer
    *     - server_id integer
    *     - domain    string
    *     - subdomain string|null
    *     - error     string
    *
    * 逻辑：
    *   1. 逐条查找节点，并从绑定机器获取 IP
    *   2. 调用 DNS 工具 resolveRecord
    *   3. 成功项批量更新节点 host
     
     * @param Request $request
     * @return JsonResponse
     */
    public function batchBindDomain(Request $request): JsonResponse
    {
        $request->validate([
            'bindings'             => 'required|array|min:1',
            'bindings.*.id'        => 'required|integer',
            'bindings.*.domain'    => 'required|string',
            'bindings.*.subdomain' => 'nullable|string',
            'bindings.*.unique'    => 'nullable|boolean',
        ]);

        // ── 2. 逐条绑定 ───────────────────────────────────────────────────
        $dnsService = new DnsToolService();
        $results    = [];
        $errors     = [];
        $updates    = [];
        foreach ($request->input('bindings') as $index => $binding) {
            $serverId  = $binding['id'];
            $domain    = $binding['domain'];
            $subdomain = $binding['subdomain'] ?? '';
            $unique    = (bool) ($binding['unique'] ?? true);

            $server = Server::find($serverId);
            if (!$server) {
                $errors[] = [
                    'index'     => $index,
                    'server_id' => $serverId,
                    'domain'    => $domain,
                    'subdomain' => $subdomain,
                    'error'     => '节点不存在',
                ];
                continue;
            }

            if (!$server->machine_id) {
                $errors[] = [
                    'index'     => $index,
                    'server_id' => $server->id,
                    'domain'    => $domain,
                    'subdomain' => $subdomain,
                    'error'     => '节点未绑定机器',
                ];
                continue;
            }

            $machine = Machine::find($server->machine_id);
            if (!$machine) {
                $errors[] = [
                    'index'     => $index,
                    'server_id' => $server->id,
                    'domain'    => $domain,
                    'subdomain' => $subdomain,
                    'error'     => '机器不存在',
                ];
                continue;
            }

            $ip = $machine->ip_address;
            if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
                $errors[] = [
                    'index'     => $index,
                    'server_id' => $server->id,
                    'domain'    => $domain,
                    'subdomain' => $subdomain,
                    'error'     => '机器IP地址无效',
                ];
                continue;
            }

            try {
                $result = $dnsService->resolveRecord($ip, $subdomain, $domain, $unique);

                // 兼容多种返回结构：{fqdn:...} / [{fqdn:...}] / 字符串
                $fqdn = null;
                if (is_array($result)) {
                    $fqdn = $result['fqdn']
                        ?? (isset($result[0])
                            ? (is_array($result[0]) ? ($result[0]['fqdn'] ?? null) : $result[0])
                            : null);
                } elseif (is_string($result)) {
                    $fqdn = $result;
                }

                // 兜底：自行拼接
                if ($fqdn === null) {
                    $fqdn = $subdomain ? "{$subdomain}.{$domain}" : $domain;
                }

                $updates[] = [
                    'id'         => $server->id,
                    'host'       => $fqdn,
                    'updated_at' => now(),
                ];

                $results[] = [
                    'index'     => $index,
                    'server_id' => $server->id,
                    'machine_id'=> $machine->id,
                    'domain'    => $domain,
                    'subdomain' => $subdomain,
                    'unique'    => $unique,
                    'resolved_ip' => $ip,
                    'fqdn'      => $fqdn,
                    'updated_host' => $fqdn,
                    'success'   => true,
                ];
            } catch (\Throwable $e) {
                Log::warning('batchBindDomain: resolveRecord failed', [
                    'server_id' => $server->id,
                    'ip'        => $ip,
                    'domain'    => $domain,
                    'subdomain' => $subdomain,
                    'error'     => $e->getMessage(),
                ]);
                $errors[] = [
                    'index'     => $index,
                    'server_id' => $server->id,
                    'domain'    => $domain,
                    'subdomain' => $subdomain,
                    'error'     => $e->getMessage(),
                ];
            }
        }

        if (!empty($updates)) {
            try {
                    $table = (new Server())->getTable();
                    $cases = [];
                    $bindings = [];
                    $ids = [];

                    foreach ($updates as $row) {
                        $cases[] = 'when ? then ?';
                        $bindings[] = $row['id'];
                        $bindings[] = $row['host'];
                        $ids[] = $row['id'];
                    }

                    $bindings[] = now();
                    $idsPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                    $sql = "update `{$table}` set `host` = case `id` " . implode(' ', $cases) . " end, `updated_at` = ? where `id` in ({$idsPlaceholders})";
                    $bindings = array_merge($bindings, $ids);

                    DB::update($sql, $bindings);
            } catch (\Exception $e) {
                Log::error('batchBindDomain: failed to batch update server host', [
                    'error' => $e->getMessage(),
                ]);
                return $this->error([500, '域名绑定成功但节点地址批量更新失败: ' . $e->getMessage()]);
            }
        }

        return $this->ok([
            'results'      => $results,
            'errors'       => $errors,
        ]);
    }

    /**
     * 批量保存节点（v3）
     *
     * POST /admin/server/manage/batchSave
     *
     * Body params:
     *   servers  array  required  节点列表，每项结构与单节点 save 接口相同：
     *     - id              integer  optional  有则更新，无则创建
     *     - type            string   required
     *     - name            string   required
     *     - host            string   required
     *     - rate            numeric  required
     *     - protocol_settings  array  optional
     *     - ... 其余字段同 ServerSave
     *
     * 处理规则：
     *   - 逐条校验必填字段（type / name / host / rate）
     *   - 自动填充端口（fillPortsIfNeeded）
     *   - 自动填充 Reality 密钥（fillRealityKeysIfNeeded）
     *   - 全部在事务中执行，任意一条失败则整体回滚
     *   - 返回每条的处理结果（成功/失败）
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchSave(Request $request): JsonResponse
    {
        $request->validate([
            'servers'                    => 'required|array|min:1',
            'servers.*.type'             => 'required|string|in:' . implode(',', \App\Models\Server::VALID_TYPES),
            'servers.*.name'             => 'required|string',
            'servers.*.host'             => 'required|string',
            'servers.*.rate'             => 'required|numeric',
            'servers.*.id'               => 'nullable|integer',
            'servers.*.group_ids'        => 'nullable|array',
            'servers.*.route_ids'        => 'nullable|array',
            'servers.*.tags'             => 'nullable|array',
            'servers.*.parent_id'        => 'nullable|integer',
            'servers.*.port'             => 'nullable|integer',
            'servers.*.server_port'      => 'nullable|integer',
            'servers.*.protocol_settings'=> 'nullable|array',
            'servers.*.show'             => 'nullable',
            'servers.*.sort'             => 'nullable|integer',
            'servers.*.code'             => 'nullable|string',
            'servers.*.spectific_key'    => 'nullable|string',
            'servers.*.excludes'         => 'nullable|array',
            'servers.*.ips'              => 'nullable|array',
            'servers.*.rate_time_enable' => 'nullable|boolean',
            'servers.*.rate_time_ranges' => 'nullable|array',
            'servers.*.custom_outbounds' => 'nullable|array',
            'servers.*.custom_routes'    => 'nullable|array',
            'servers.*.cert_config'      => 'nullable|array',
        ]);

        $items   = $request->input('servers');
        $results = [];

        try {
            DB::beginTransaction();

            foreach ($items as $index => $params) {
                // 过滤掉 null 值，避免覆盖数据库默认值
                $params = array_filter($params, fn($v) => $v !== null);

                $params = $this->fillRealityKeysIfNeeded($params);
                $params = $this->fillPortsIfNeeded($params);

                $id = $params['id'] ?? null;
                unset($params['id']);

                if ($id) {
                    $server = Server::find($id);
                    if (!$server) {
                        throw new \RuntimeException("index={$index}: 节点 ID={$id} 不存在");
                    }
                    $server->update($params);
                    $results[] = [
                        'index'     => $index,
                        'id'        => $server->id,
                        'action'    => 'updated',
                        'name'      => $server->name,
                    ];
                } else {
                    $server = Server::create($params);
                    $results[] = [
                        'index'     => $index,
                        'id'        => $server->id,
                        'action'    => 'created',
                        'name'      => $server->name,
                    ];
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('batchSave failed', ['error' => $e->getMessage()]);
            return $this->error([500, '批量保存失败: ' . $e->getMessage()]);
        }

        return $this->ok([
            'total'   => count($results),
            'results' => $results,
        ]);
    }

    /**
     * 测试节点端口连通性
     *
     * POST /admin/server/manage/testPort
     *
     * Body params:
     *   id       integer  optional  节点 ID（与 host+port 二选一）
     *   host     string   optional  目标主机
     *   port     integer  optional  目标端口
     *   timeout  integer  optional  超时秒数，默认 5，最大 30
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testPort(Request $request)
    {
        $request->validate([
            'id'      => 'nullable|integer',
            'host'    => 'nullable|string',
            'port'    => 'nullable|integer|min:1|max:65535',
            'timeout' => 'nullable|integer|min:1|max:30',
        ]);

        $timeout = (int) $request->input('timeout', 5);

        // 优先使用 id 查找节点
        if ($request->filled('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->error([400202, '节点不存在']);
            }
            $host = $server->host;
            $port = (int) ($server->server_port ?: $server->port);
        } else {
            if (!$request->filled('host') || !$request->filled('port')) {
                return $this->error([422, '请提供节点 id 或 host + port']);
            }
            $host = $request->input('host');
            $port = (int) $request->input('port');
        }

        $startAt = microtime(true);
        $errno   = 0;
        $errstr  = '';
        $fp      = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $latency = round((microtime(true) - $startAt) * 1000); // ms

        if ($fp) {
            fclose($fp);
            return $this->ok([
                'host'       => $host,
                'port'       => $port,
                'reachable'  => true,
                'latency_ms' => $latency,
                'message'    => "端口 {$port} 连通正常",
            ]);
        }

        return $this->ok([
            'host'       => $host,
            'port'       => $port,
            'reachable'  => false,
            'latency_ms' => $latency,
            'message'    => $errstr ?: "端口 {$port} 无法连接",
            'errno'      => $errno,
        ]);
    }

    /**
     * 更新节点基础配置（异步执行 node-config-update.sh）
     *
     * POST /admin/server/manage/updateNodeConfig
     *
     * Body params:
     *   id       integer  required  节点 ID
     *   timeout  integer  optional  SSH 执行超时（秒），默认 300
     *
     * Response:
     *   task_id  string   任务 ID，可用于查询进度
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateBaseConfig(Request $request): JsonResponse
    {
        $request->validate([
            'id'      => 'required|integer',
            'timeout' => 'nullable|integer|min:30|max:600',
        ]);

        $server = Server::find($request->input('id'));

        $defaultEnv = [
            'API_HOST'  => config('app.url'),
            'API_KEY'   => admin_setting('server_token'),
            'CORE_TYPE' => $server->core_type ?: 'sing',
            'NODE_TYPE' => $server->type,
            'NODE_ID'   => $server->id,
            'CERT_MODE' => 'dns',
            'CERT_DOMAIN' => $server->host,
            'CERT_EMAIL' => env('TLS_EMAIL', ''),
        ];

        if (!$server) {
            return $this->error([400202, '节点不存在']);
        }

        if (!$server->machine_id) {
            return $this->error([422, '节点未绑定机器']);
        }

        $machine = Machine::find($server->machine_id);
        if (!$machine) {
            return $this->error([422, '机器不存在']);
        }

        $timeout = (int) $request->input('timeout', 300);

        try {
            $taskId = RemoteScriptService::dispatch(
                $machine->id,
                'node-config-update.sh',
                [],
                true,
                $timeout
            );

            return $this->ok([
                'task_id'    => $taskId,
                'server_id'  => $server->id,
                'machine_id' => $machine->id,
                'message'    => '配置更新任务已提交',
            ]);
        } catch (\Exception $e) {
            Log::error('updateNodeConfig failed', [
                'server_id'  => $server->id,
                'machine_id' => $machine->id,
                'error'      => $e->getMessage(),
            ]);
            return $this->error([500, '提交配置更新任务失败: ' . $e->getMessage()]);
        }
    }

    
}
