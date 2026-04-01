<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Machine;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerTemplate;
use App\Services\DnsToolService;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        $servers = ServerService::getAllServers()->map(function ($item) {
            $item['groups'] = ServerGroup::whereIn('id', $item['group_ids'])->get(['name', 'id']);
            $item['parent'] = $item->parent;
            return $item;
        });
        return $this->success($servers);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                $server->update($params);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        try {
            Server::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }
    }

    /**
     * 根据机器ID + 节点模板创建节点
     *
     * POST /admin/server/manage/saveFromMachineTemplate
     *
     * 优先级（高 → 低）：
     *   1. 请求体中明确传入的字段
     *   2. 节点模板中的字段（含 generation_options 随机化）
     *
     * host 确定规则：
     *   1. 请求中若传了 host，直接使用
     *   2. 否则查询 DNS 工具：该机器 IP 是否绑定了域名 → 有则取第一条 fqdn
     *   3. 最后回退到机器 ip_address
     */
    public function saveFromMachineTemplate(Request $request)
    {
        $request->validate([
            'template_id' => 'required|integer|exists:v2_server_template,id',
            'machine_id'  => 'required|integer|exists:v2_machine,id',
            'name'        => 'required|string|max:100',
        ]);

        $template = ServerTemplate::findOrFail($request->integer('template_id'));
        $machine  = Machine::findOrFail($request->integer('machine_id'));

        // ── 1. 确定 host ──────────────────────────────────────────────────
        if ($request->filled('host')) {
            $host = $request->input('host');
        } else {
            $host = $machine->ip_address;

            // 尝试从 DNS 工具查询该 IP 绑定的域名
            try {
                $records = (new DnsToolService())->getRecordsByIp($machine->ip_address);
                // 返回可能是 [{fqdn:...}, ...] 或直接是字符串列表，取第一条
                if (!empty($records)) {
                    $first = is_array($records[0]) ? ($records[0]['fqdn'] ?? null) : $records[0];
                    if ($first) {
                        $host = $first;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('saveFromMachineTemplate: DNS lookup failed', [
                    'ip'    => $machine->ip_address,
                    'error' => $e->getMessage(),
                ]);
                // DNS 查询失败不阻断流程，继续用 IP
            }
        }

        // ── 2. 基础配置：模板展开 ─────────────────────────────────────────
        $baseConfig = $template->toServerConfig();

        // ── 3. 请求中明确传入的覆盖字段（排除元字段）────────────────────
        $skip      = ['template_id', 'machine_id', 'generation_options'];
        $overrides = array_filter(
            $request->except($skip),
            fn($v) => $v !== null
        );

        $config = array_merge($baseConfig, $overrides, ['host' => $host]);

        // ── 4. 合并 generation_options（请求 > 模板），应用随机化 ─────────
        $templateOpts = $template->generation_options ?? [];
        $requestOpts  = array_filter((array) $request->input('generation_options', []), fn($v) => $v !== null);
        $mergedOpts   = array_merge($templateOpts, $requestOpts);

        $template->generation_options = $mergedOpts;
        $config = $template->applyGenerationOptions($config);

        // ── 5. 必填字段检查 ───────────────────────────────────────────────
        if (empty($config['type'])) {
            return $this->fail([422, '模板未设置节点类型（type），请先完善模板']);
        }
        if (empty($config['name'])) {
            $config['name'] = $request->input('name');
        }

        // ── 6. 创建节点 ────────────────────────────────────────────────────
        try {
            $server = Server::create($config);
        } catch (\Exception $e) {
            Log::error('saveFromMachineTemplate failed', [
                'template_id' => $template->id,
                'machine_id'  => $machine->id,
                'error'       => $e->getMessage(),
            ]);
            return $this->error([500, '节点创建失败: ' . $e->getMessage()]);
        }

        return $this->ok([
            'server_id'   => $server->id,
            'host'        => $server->host,
            'resolved_ip' => $machine->ip_address,
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'show' => 'integer',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        $server->show = (int) $request->show;
        if (!$server->save()) {
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        if (Server::where('id', $request->id)->delete() === false) {
            return $this->fail([500, '删除失败']);
        }
        return $this->success(true);
    }


    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        $server->show = 0;
        $server->code = null;
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        Server::create($server->toArray());
        return $this->success(true);
    }
}
