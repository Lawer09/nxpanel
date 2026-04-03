<?php

namespace App\Http\Controllers\V3\Admin\Server;

use App\Http\Controllers\V2\Admin\Server\ManageController as V2ManageController;
use App\Models\Server;
use App\Services\DnsToolService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ManageController extends V2ManageController
{
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
}
