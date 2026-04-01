<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Jobs\DeployServerJob;
use App\Models\Machine;
use App\Models\NodeDeployTask;
use App\Models\Server;
use App\Services\NodeDeployService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 节点管理 - 部署接口
 *
 * 通过节点 ID 向宿主机（按 server.host 匹配 machines.ip_address）执行
 * node-install.sh，固定环境变量：
 *   API_HOST  = https://pupu.apptilaus.com
 *   API_KEY   = 服务器节点密钥（admin_setting server_token）
 *   NODE_ID   = 节点ID
 *   CORE_TYPE = sing
 *   NODE_TYPE = 协议（vless=2 vmess=3 trojan=6 等）
 */
class ServerDeployController extends Controller
{
    /**
     * 单节点部署（异步队列）
     *
     * POST /admin/server/manage/deploy
     * {
     *   "server_id":  1,
     *   "machine_id": 1   // 可选；不传时自动按 host/IP 查找
     * }
     */
    public function deploy(Request $request)
    {
        $request->validate([
            'server_id'  => 'required|integer|exists:v2_server,id',
            'machine_id' => 'nullable|integer|exists:v2_machine,id',
        ]);

        $server  = Server::findOrFail($request->integer('server_id'));
        $machine = $this->resolveMachine($server, $request->input('machine_id'));

        if (!$machine) {
            return $this->error([422, "找不到与节点 host ({$server->host}) 对应的机器，请手动传入 machine_id"]);
        }

        $task = NodeDeployTask::create([
            'machine_id'    => $machine->id,
            'server_id'     => $server->id,
            'status'        => NodeDeployTask::STATUS_PENDING,
            'deploy_config' => NodeDeployService::buildServerEnvVars($server),
        ]);

        DeployServerJob::dispatch($task->id, $server->id)->onQueue('deploy');

        return $this->ok([
            'task_id'    => $task->id,
            'server_id'  => $server->id,
            'machine_id' => $machine->id,
            'status'     => $task->status,
        ]);
    }

    /**
     * 批量节点部署（异步队列）
     *
     * POST /admin/server/manage/batchServerDeploy
     * {
     *   "server_ids": [1, 2, 3]
     * }
     */
    public function batchDeploy(Request $request)
    {
        $request->validate([
            'server_ids'   => 'required|array|min:1|max:50',
            'server_ids.*' => 'integer',
        ]);

        $serverIds = $request->input('server_ids');
        $servers   = Server::whereIn('id', $serverIds)->get()->keyBy('id');

        $missing = array_diff($serverIds, $servers->keys()->toArray());
        if (!empty($missing)) {
            return $this->error([404, '以下节点ID不存在: ' . implode(', ', $missing)]);
        }

        $batchId = (int) (microtime(true) * 1000);
        $tasks   = [];

        $skipped = [];

        DB::beginTransaction();
        try {
            foreach ($servers as $server) {
                $machine = $this->resolveMachine($server);
                if (!$machine) {
                    $skipped[] = ['server_id' => $server->id, 'reason' => "找不到与 host ({$server->host}) 对应的机器"];
                    continue;
                }
                $task = NodeDeployTask::create([
                    'batch_id'      => $batchId,
                    'machine_id'    => $machine->id,
                    'server_id'     => $server->id,
                    'status'        => NodeDeployTask::STATUS_PENDING,
                    'deploy_config' => NodeDeployService::buildServerEnvVars($server),
                ]);
                $tasks[] = $task;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([500, '任务创建失败: ' . $e->getMessage()]);
        }

        foreach ($tasks as $task) {
            DeployServerJob::dispatch($task->id, $task->server_id)->onQueue('deploy');
        }

        return $this->ok([
            'batch_id'   => $batchId,
            'task_count' => count($tasks),
            'tasks'      => collect($tasks)->map(fn($t) => [
                'task_id'    => $t->id,
                'server_id'  => $t->server_id,
                'machine_id' => $t->machine_id,
                'status'     => $t->status,
            ]),
            'skipped'    => $skipped,
        ]);
    }

    /**
     * 查询部署任务结果
     *
     * GET /admin/server/manage/deployResult?task_id=1
     * GET /admin/server/manage/deployResult?batch_id=1234567890
     * GET /admin/server/manage/deployResult?server_id=1   （最新一条）
     */
    public function deployResult(Request $request)
    {
        $request->validate([
            'task_id'   => 'nullable|integer',
            'batch_id'  => 'nullable|integer',
            'server_id' => 'nullable|integer',
        ]);

        if ($request->filled('task_id')) {
            $task = NodeDeployTask::with('server:id,name,type,host')
                ->findOrFail($request->integer('task_id'));
            return $this->ok($task);
        }

        if ($request->filled('batch_id')) {
            $tasks = NodeDeployTask::with('server:id,name,type,host')
                ->where('batch_id', $request->integer('batch_id'))
                ->orderBy('id')
                ->get();

            $summary = [
                'total'   => $tasks->count(),
                'pending' => $tasks->where('status', NodeDeployTask::STATUS_PENDING)->count(),
                'running' => $tasks->where('status', NodeDeployTask::STATUS_RUNNING)->count(),
                'success' => $tasks->where('status', NodeDeployTask::STATUS_SUCCESS)->count(),
                'failed'  => $tasks->where('status', NodeDeployTask::STATUS_FAILED)->count(),
            ];

            return $this->ok(['summary' => $summary, 'tasks' => $tasks]);
        }

        if ($request->filled('server_id')) {
            $task = NodeDeployTask::with('server:id,name,type,host')
                ->where('server_id', $request->integer('server_id'))
                ->latest('id')
                ->firstOrFail();
            return $this->ok($task);
        }

        return $this->error([422, '请传入 task_id、batch_id 或 server_id']);
    }

    /**
     * 查找与节点对应的机器，查找顺序：
     *   1. 指定 machine_id
     *   2. machine.ip_address == server.host
     *   3. machine.hostname   == server.host
     *   4. server.host 是域名 → ping 解析 IP → 再按 ip_address 匹配
     */
    private function resolveMachine(Server $server, ?int $machineId = null): ?Machine
    {
        // 1. 明确指定
        if ($machineId) {
            return Machine::find($machineId);
        }

        $host = $server->host;

        // 2. 直接按 ip_address 或 hostname 匹配
        $machine = Machine::where('ip_address', $host)
            ->orWhere('hostname', $host)
            ->first();
        if ($machine) {
            return $machine;
        }

        // 3. host 是域名 → ping 解析 IP，再按 ip_address 匹配
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $this->resolveHostToIp($host);
            if ($ip) {
                $machine = Machine::where('ip_address', $ip)->first();
                if ($machine) {
                    return $machine;
                }
            }
        }

        return null;
    }

    /**
     * 通过 ping/DNS 将域名解析为 IP（仅取第一个结果）
     */
    private function resolveHostToIp(string $host): ?string
    {
        try {
            $records = dns_get_record($host, DNS_A);
            if (!empty($records[0]['ip'])) {
                return $records[0]['ip'];
            }
        } catch (\Throwable $e) {
            Log::warning('resolveMachine: dns_get_record failed', ['host' => $host, 'error' => $e->getMessage()]);
        }

        // fallback: gethostbyname
        $ip = gethostbyname($host);
        return ($ip !== $host) ? $ip : null;
    }
}
