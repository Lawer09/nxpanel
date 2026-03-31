<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeployNodeJob;
use App\Models\Machine;
use App\Models\NodeDeployTask;
use App\Services\MachineSSHService;
use App\Services\NodeDeployService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MachineController extends Controller
{
    /**
     * 获取机器列表
     */
    public function fetch(Request $request)
    {
        try {
            $page = $request->query('page', 1);
            $pageSize = $request->query('pageSize', 10);
            $name = $request->query('name');
            $status = $request->query('status');
            $hostname = $request->query('hostname');
            $tags = $request->query('tags');

            $query = Machine::query();

            if ($name) {
                $query->where('name', 'like', "%{$name}%");
            }

            if ($status) {
                $query->where('status', $status);
            }

            if ($hostname) {
                $query->where('hostname', 'like', "%{$hostname}%");
            }

            if ($tags) {
                $query->where('tags', 'like', "%{$tags}%");
            }

            $total = $query->count();
            $data = $query->orderByDesc('created_at')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get()
                ->makeHidden(['password', 'private_key']);

            return $this->ok([
                'data'     => $data,
                'total'    => $total,
                'pageSize' => $pageSize,
                'page'     => $page,
            ]);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 创建机器
     */
    public function save(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'hostname' => 'required|string|unique:machines,hostname|max:255',
                'ip_address' => 'required|string|max:255',
                'port' => 'required|integer|min:1|max:65535',
                'username' => 'required|string|max:255',
                'password' => 'nullable|string',
                'private_key' => 'nullable|string',
                'os_type' => 'nullable|string|max:255',
                'cpu_cores' => 'nullable|string',
                'memory' => 'nullable|string',
                'disk' => 'nullable|string',
                'description' => 'nullable|string',
                'is_active' => 'nullable|boolean',
                'gpu_info' => 'nullable|string',
                'bandwidth' => 'nullable|integer',
                'provider' => 'nullable|integer',
                'price' => 'nullable|decimal:8,2',
                'pay_mode' => 'nullable|integer',
                'tags' => 'nullable|string',
            ]);

            // 密码和私钥至少需要一个
            if (empty($validated['password']) && empty($validated['private_key'])) {
                return $this->error([422, '密码和私钥至少需要一个']);
            }

            $validated['is_active'] = $validated['is_active'] ?? true;
            $validated['status'] = 'offline';

            $machine = Machine::create($validated);

            return $this->ok($machine->makeHidden(['password', 'private_key']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, '数据验证失败']);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新机器
     */
    public function update(Request $request)
    {
        try {
            $id = $request->input('id');
            
            if (!$id) {
                return $this->error([422, '机器ID不能为空']);
            }

            $machine = Machine::findOrFail($id);

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'hostname' => 'sometimes|required|string|unique:machines,hostname,' . $id . '|max:255',
                'ip_address' => 'sometimes|required|string|max:255',
                'port' => 'sometimes|required|integer|min:1|max:65535',
                'username' => 'sometimes|required|string|max:255',
                'password' => 'nullable|string',
                'private_key' => 'nullable|string',
                'status' => 'sometimes|in:online,offline,error',
                'os_type' => 'nullable|string|max:255',
                'cpu_cores' => 'nullable|string',
                'memory' => 'nullable|string',
                'disk' => 'nullable|string',
                'description' => 'nullable|string',
                'is_active' => 'nullable|boolean',
                'gpu_info' => 'nullable|string',
                'bandwidth' => 'nullable|integer',
                'provider' => 'nullable|integer',
                'price' => 'nullable|decimal:8,2',
                'pay_mode' => 'nullable|integer',
                'tags' => 'nullable|string',
            ]);

            $machine->update($validated);

            return $this->ok($machine->fresh()->makeHidden(['password', 'private_key']));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, '数据验证失败']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error([404, '机器不存在']);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 删除机器
     */
    public function drop(Request $request)
    {
        try {
            $id = $request->input('id');

            if (!$id) {
                return $this->error([422, '机器ID不能为空']);
            }

            $machine = Machine::findOrFail($id);
            $machine->delete();

            return $this->ok(true);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error([404, '机器不存在']);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 获取机器详情
     */
    public function detail(Request $request)
    {
        try {
            $id = $request->input('id');

            if (!$id) {
                return $this->error([422, '机器ID不能为空']);
            }

            $machine = Machine::findOrFail($id);

            return $this->ok($machine->makeHidden(['password', 'private_key']));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error([404, '机器不存在']);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**  
     * 测试SSH连接  
     */  
    public function testConnection(Request $request)  
    {  
        try {  
            $id = $request->input('id');  
  
            if (!$id) {
                return $this->error([422, '机器ID不能为空']);
            }

            $machine = Machine::findOrFail($id);

            $service = new MachineSSHService();
            $ssh = $service->connect($machine);

            $osInfo = trim($ssh->exec('uname -a') ?? '');
            $ssh->disconnect();

            $machine->update([
                'status' => 'online',
                'last_check_at' => now()
            ]);

            return $this->ok([
                'status'  => 'online',
                'os_info' => $osInfo,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error([404, '机器不存在']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, '参数验证失败']);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            return $this->error([422, '密码或私钥解密失败，请重新编辑该机器并保存密码/私钥']);
        } catch (\Exception $e) {
            if (isset($machine) && $machine) {
                $machine->update([
                    'status' => 'error',
                    'last_check_at' => now()
                ]);
            }
            return $this->error([500, 'SSH连接失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 批量删除
     */
    public function batchDrop(Request $request)
    {
        try {
            $ids = $request->input('ids', []);

            if (empty($ids)) {
                return $this->error([422, '请选择要删除的机器']);
            }

            Machine::whereIn('id', $ids)->delete();

            return $this->ok(true);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 单机部署节点（异步）
     *
     * POST /admin/machine/deployNode
     * Body:
     * {
     *   "id": 1,
     *   "template_id": 2,           // 可选：配置模板ID（优先级低于 deploy_config）
     *   "deploy_config": {           // 可选：直接传入配置，会覆盖模板同名字段
     *     "node_name": "香港01",
     *     "port": 12345
     *   }
     * }
     */
    public function deployNode(Request $request)
    {
        $request->validate([
            'id'            => 'required|integer',
            'template_id'   => 'nullable|integer',
            'deploy_config' => 'nullable|array',
        ]);

        try {
            $machine    = Machine::findOrFail($request->integer('id'));
            $templateId = $request->integer('template_id') ?: null;
            $override   = $request->input('deploy_config', []);

            // 模板配置 + 请求配置合并
            $cfg = NodeDeployService::resolveConfig($override, $templateId);

            // 如未传 node_name，用机器名/IP 作默认
            if (empty($cfg['node_name'])) {
                $cfg['node_name'] = $machine->name ?? $machine->ip_address;
            }

            $task = NodeDeployTask::create([
                'machine_id'    => $machine->id,
                'status'        => NodeDeployTask::STATUS_PENDING,
                'deploy_config' => $cfg,
            ]);

            DeployNodeJob::dispatch($task->id, $machine->id, $cfg)->onQueue('deploy');

            return $this->ok([
                'task_id'     => $task->id,
                'template_id' => $templateId,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error([404, '机器不存在']);
        } catch (\Exception $e) {
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 批量部署节点
     *
     * POST /admin/machine/batchDeploy
     * Body:
     * {
     *   "machine_ids": [1, 2, 3],
     *   "template_id": 2,               // 可选：公共配置模板
     *   "deploy_config": {              // 可选：覆盖/追加模板字段（公共）
     *     "node_type": "vless",
     *     "group_ids": ["2"]
     *   },
     *   "machine_configs": {            // 可选：按机器ID单独覆盖（最高优先级）
     *     "1": { "node_name": "香港01", "port": 12345 },
     *     "2": { "node_name": "日本01" }
     *   }
     * }
     * 配置优先级（低→高）：模板 < deploy_config < machine_configs[id]
     */
    public function batchDeploy(Request $request)
    {
        $request->validate([
            'machine_ids'              => 'required|array|min:1|max:50',
            'machine_ids.*'            => 'integer',
            'template_id'              => 'nullable|integer',
            'deploy_config'            => 'nullable|array',
            'machine_configs'          => 'nullable|array',
        ]);

        $machineIds     = $request->input('machine_ids');
        $templateId     = $request->integer('template_id') ?: null;
        $baseConfig     = NodeDeployService::resolveConfig($request->input('deploy_config', []), $templateId);
        $machineConfigs = $request->input('machine_configs', []);

        $machines = Machine::whereIn('id', $machineIds)->get()->keyBy('id');

        $missing = array_diff($machineIds, $machines->keys()->toArray());
        if (!empty($missing)) {
            return $this->error([404, '以下机器ID不存在: ' . implode(', ', $missing)]);
        }

        $batchId = (int) (microtime(true) * 1000); // 毫秒时间戳作为批次ID
        $tasks   = [];

        DB::beginTransaction();
        try {
            foreach ($machineIds as $mid) {
                // 合并公共配置 + 机器专属配置
                $cfg = array_merge($baseConfig, $machineConfigs[(string) $mid] ?? []);

                // 如果没有传 node_name，默认用机器名/IP
                if (empty($cfg['node_name'])) {
                    $m = $machines[$mid];
                    $cfg['node_name'] = $m->name ?? $m->ip_address;
                }

                $task = NodeDeployTask::create([
                    'batch_id'      => $batchId,
                    'machine_id'    => $mid,
                    'status'        => NodeDeployTask::STATUS_PENDING,
                    'deploy_config' => $cfg,
                ]);

                $tasks[] = $task;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([500, '任务创建失败: ' . $e->getMessage()]);
        }

        // 批量派发异步 Job
        foreach ($tasks as $task) {
            DeployNodeJob::dispatch($task->id, $task->machine_id, $task->deploy_config)
                         ->onQueue('deploy');
        }

        return $this->ok([
            'batch_id'   => $batchId,
            'task_count' => count($tasks),
            'tasks'      => collect($tasks)->map(fn($t) => [
                'task_id'    => $t->id,
                'machine_id' => $t->machine_id,
                'status'     => $t->status,
            ]),
        ]);
    }

    /**
     * 查询部署任务状态
     *
     * GET /admin/machine/deployStatus?batch_id=xxx
     * GET /admin/machine/deployStatus?task_id=xxx
     */
    public function deployStatus(Request $request)
    {
        $request->validate([
            'batch_id' => 'nullable|integer',
            'task_id'  => 'nullable|integer',
        ]);

        if ($request->filled('task_id')) {
            $task = NodeDeployTask::with(['machine:id,name,ip_address', 'server:id,name'])
                ->findOrFail($request->integer('task_id'));
            return $this->ok($task);
        }

        if ($request->filled('batch_id')) {
            $tasks = NodeDeployTask::with(['machine:id,name,ip_address', 'server:id,name'])
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

        return $this->error([422, '请传入 batch_id 或 task_id']);
    }  
  
    /**  
     * 清除节点  
     */  
    public function clearNode(Request $request)  
    {  
        try {  
            $id = $request->input('id');  
  
            if (!$id) {  
                return response()->json([  
                    'code' => 1,  
                    'msg' => '机器ID不能为空'  
                ]);  
            }  
  
            $machine = Machine::findOrFail($id);  
  
            $service = new MachineSSHService();  
            $result = $service->executeScript($machine, 'node-clear.sh');  
  
            // 清除成功后更新状态为 offline  
            if ($result['exit_code'] === 0) {  
                $machine->update([  
                    'status' => 'offline',  
                    'last_check_at' => now()  
                ]);  
            }  
  
            return response()->json([  
                'code' => $result['exit_code'] === 0 ? 0 : 1,  
                'msg' => $result['exit_code'] === 0 ? '节点清除完成' : '节点清除失败',  
                'data' => [  
                    'output' => $result['output'],  
                    'exit_code' => $result['exit_code']  
                ]  
            ]);  
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {  
            return response()->json([  
                'code' => 1,  
                'msg' => '机器不存在'  
            ]);  
        } catch (\Exception $e) {  
            return response()->json([  
                'code' => 1,  
                'msg' => $e->getMessage()  
            ]);  
        }  
    }
}