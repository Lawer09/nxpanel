<?php

namespace App\Http\Controllers\V3\Admin\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController as V2ManageController;
use App\Models\Server;
use App\Models\ServerTemplate;
use App\Models\User;
use App\Services\DnsToolService;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends V2ManageController
{
    /**
     * 查看节点当前在线用户（v3）
     *
     * GET /admin/server/manage/onlineUsers?id={serverId}
     *
    * 从 Redis 缓存 USER_ONLINE_CONN_{nodeType}_{nodeId}_{userId} 中查询在线用户连接数。
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
            return $this->fail([400202, '节点不存在']);
        }

        // 构造该节点在缓存中的 key，如 "vless5"、"trojan9"
        $nodeType = strtoupper($server->type);
        $nodeId = $server->id;

        // 查询所有 online_count > 0 的用户（缩小扫描范围）
        $users = User::query()
            ->where('online_count', '>', 0)
            ->select(['id', 'email', 'online_count', 'last_online_at'])
            ->get();

        $onlineUsers = [];

        foreach ($users as $user) {
            $cacheKey = CacheKey::get("USER_ONLINE_CONN_{$nodeType}_{$nodeId}", $user->id);
            $conn = cache()->get($cacheKey);

            if (empty($conn)) {
                continue;
            }

            $onlineUsers[] = [
                'user_id'        => $user->id,
                'email'          => $user->email,
                'conn'           => (int) $conn,
                'last_online_at' => $user->last_online_at,
            ];
        }

        return $this->ok([
            'server_id'    => $server->id,
            'server_name'  => $server->name,
            'node_key'     => strtolower($nodeType) . $nodeId,
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
     *   id        integer   required  节点 ID
     *   bindings  array     required  绑定列表，每项包含：
     *     - domain    string   required  主域名（如 example.com）
     *     - subdomain string   nullable  子域名前缀（为空则直接解析到主域名）
     *     - unique    boolean  optional  是否唯一解析，默认 false
     *
     * 逻辑：
     *   1. 查找节点，若 host 为域名则先用系统 DNS 解析为 IP
     *   2. 逐条调用 DNS 工具 resolveRecord
     *   3. 全部处理完毕后，取第一条成功绑定的 fqdn 更新节点 host
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function batchBindDomain(Request $request): JsonResponse
    {
        $request->validate([
            'id'                   => 'required|integer',
            'bindings'             => 'required|array|min:1',
            'bindings.*.domain'    => 'required|string',
            'bindings.*.subdomain' => 'nullable|string',
            'bindings.*.unique'    => 'nullable|boolean',
        ]);

        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '节点不存在']);
        }

        // ── 1. 确定节点 IP ────────────────────────────────────────────────
        $host = $server->host;
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;

        if ($isIp) {
            $ip = $host;
        } else {
            // host 是域名，通过系统 DNS 解析为 IP
            $resolved = gethostbyname($host);
            if ($resolved === $host) {
                return $this->fail([422, "节点 host ({$host}) 无法解析为 IP，请先确认 DNS 配置"]);
            }
            $ip = $resolved;
        }

        // ── 2. 逐条绑定 ───────────────────────────────────────────────────
        $dnsService = new DnsToolService();
        $results    = [];
        $errors     = [];
        $firstFqdn  = null;

        foreach ($request->input('bindings') as $index => $binding) {
            $domain    = $binding['domain'];
            $subdomain = $binding['subdomain'] ?? '';
            $unique    = (bool) ($binding['unique'] ?? false);

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

                if ($firstFqdn === null) {
                    $firstFqdn = $fqdn;
                }

                $results[] = [
                    'index'     => $index,
                    'domain'    => $domain,
                    'subdomain' => $subdomain,
                    'unique'    => $unique,
                    'fqdn'      => $fqdn,
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
                    'domain'    => $domain,
                    'subdomain' => $subdomain,
                    'error'     => $e->getMessage(),
                ];
            }
        }

        // ── 3. 更新节点 host（取第一条成功绑定的 fqdn）──────────────────
        $updatedHost = null;
        if ($firstFqdn !== null) {
            try {
                $server->host = $firstFqdn;
                $server->save();
                $updatedHost = $firstFqdn;
            } catch (\Exception $e) {
                Log::error('batchBindDomain: failed to update server host', [
                    'server_id' => $server->id,
                    'fqdn'      => $firstFqdn,
                    'error'     => $e->getMessage(),
                ]);
                return $this->fail([500, '域名绑定成功但节点地址更新失败: ' . $e->getMessage()]);
            }
        }

        return $this->ok([
            'server_id'    => $server->id,
            'resolved_ip'  => $ip,
            'updated_host' => $updatedHost,
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
            return $this->fail([500, '批量保存失败: ' . $e->getMessage()]);
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
                return $this->fail([400202, '节点不存在']);
            }
            $host = $server->host;
            $port = (int) ($server->server_port ?: $server->port);
        } else {
            if (!$request->filled('host') || !$request->filled('port')) {
                return $this->fail([422, '请提供节点 id 或 host + port']);
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

}
