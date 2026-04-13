<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeployNodeJob;
use App\Models\Machine;
use App\Models\NodeDeployTask;
use App\Services\MachineSSHService;
use App\Services\NodeDeployService;
use App\Services\CloudProvider\CloudProviderManager;
use App\Services\CloudProvider\OperationNotSupportedException;
use Illuminate\Support\Facades\Log;
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
            $data = $query->with(['ips' => function ($q) {
                $q->withPivot(['is_primary', 'is_egress', 'bind_status', 'bound_at', 'unbound_at']);
            }])->orderByDesc('created_at')
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
                'name'                 => 'required|string|max:255',
                'hostname'             => 'required|string|unique:machines,hostname|max:255',
                'ip_address'           => 'required|string|max:255',
                'private_ip_address'   => 'required|string|max:255',
                'port'                 => 'required|integer|min:1|max:65535',
                'username'             => 'required|string|max:255',
                'password'             => 'nullable|string',
                'private_key'          => 'nullable|string',
                'os_type'              => 'nullable|string|max:255',
                'cpu_cores'            => 'nullable|string',
                'memory'               => 'nullable|string',
                'disk'                 => 'nullable|string',
                'description'          => 'nullable|string',
                'is_active'            => 'nullable|boolean',
                'gpu_info'             => 'nullable|string',
                'bandwidth'            => 'nullable|integer',
                'provider'             => 'nullable|integer',
                'price'                => 'nullable|decimal:8,2',
                'pay_mode'             => 'nullable|integer',
                'tags'                 => 'nullable|string',
                'provider_instance_id' => 'nullable|string|max:255',
                'provider_nic_id'      => 'nullable|string|max:255',
            ]);

            // 密码和私钥至少需要一个
            if (empty($validated['password']) && empty($validated['private_key'])) {
                return $this->error([422, '密码和私钥至少需要一个']);
            }

            // 创建时检查云端实例是否重复（provider + provider_instance_id 组合）
            if (!empty($validated['provider']) && !empty($validated['provider_instance_id'])) {
                $exists = Machine::where('provider', $validated['provider'])
                    ->where('provider_instance_id', $validated['provider_instance_id'])
                    ->exists();

                if ($exists) {
                    return $this->error([422, '云端实例重复']);
                }
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
                'name'                 => 'sometimes|required|string|max:255',
                'hostname'             => 'sometimes|required|string|unique:machines,hostname,' . $id . '|max:255',
                'ip_address'           => 'sometimes|required|string|max:255',
                'private_ip_address'   => 'sometimes|required|string|max:255',
                'port'                 => 'sometimes|required|integer|min:1|max:65535',
                'username'             => 'sometimes|required|string|max:255',
                'password'             => 'nullable|string',
                'private_key'          => 'nullable|string',
                'status'               => 'sometimes|in:online,offline,error',
                'os_type'              => 'nullable|string|max:255',
                'cpu_cores'            => 'nullable|string',
                'memory'               => 'nullable|string',
                'disk'                 => 'nullable|string',
                'description'          => 'nullable|string',
                'is_active'            => 'nullable|boolean',
                'gpu_info'             => 'nullable|string',
                'bandwidth'            => 'nullable|integer',
                'provider'             => 'nullable|integer',
                'price'                => 'nullable|decimal:8,2',
                'pay_mode'             => 'nullable|integer',
                'tags'                 => 'nullable|string',
                'provider_instance_id' => 'nullable|string|max:255',
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
     * 批量导入机器
     *
     * 当 provider_instance_id 存在时：按 provider_instance_id 更新
     * 否则：创建新机器
     *
     * POST /admin/machine/batchImport
     * {
     *   "items": [
     *     {
     *       "name": "xxx",
     *       "hostname": "xxx",
     *       "ip_address": "1.2.3.4",
     *       "port": 22,
     *       "username": "root",
     *       "password": "***",
     *       "provider": 1,
     *       "provider_instance_id": "i-xxx"
     *     }
     *   ]
     * }
     */
    public function batchImport(Request $request)
    {
        $items = $request->input('items', []);

        if (empty($items) || !is_array($items)) {
            return $this->error([422, '导入数据不能为空']);
        }

        $allowedFields = [
            'name',
            'hostname',
            'ip_address',
            'private_ip_address',
            'port',
            'username',
            'password',
            'private_key',
            'status',
            'os_type',
            'cpu_cores',
            'memory',
            'disk',
            'description',
            'is_active',
            'gpu_info',
            'bandwidth',
            'provider',
            'price',
            'pay_mode',
            'tags',
            'provider_instance_id',
            'provider_nic_id',
        ];

        $created = [];
        $updated = [];
        $failed = [];

        DB::beginTransaction();
        try {
            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    $failed[] = [
                        'index' => $index,
                        'reason' => '数据格式错误，必须为对象',
                    ];
                    continue;
                }

                $data = array_intersect_key($item, array_flip($allowedFields));
                $providerInstanceId = $data['provider_instance_id'] ?? null;
                $provider = $data['provider'] ?? null;

                if (isset($data['status']) && !in_array($data['status'], ['online', 'offline', 'error'], true)) {
                    $failed[] = [
                        'index' => $index,
                        'provider_instance_id' => $providerInstanceId,
                        'reason' => 'status 只能是 online/offline/error',
                    ];
                    continue;
                }

                if (isset($data['port']) && (!is_numeric($data['port']) || $data['port'] < 1 || $data['port'] > 65535)) {
                    $failed[] = [
                        'index' => $index,
                        'provider_instance_id' => $providerInstanceId,
                        'reason' => '端口必须在 1-65535 之间',
                    ];
                    continue;
                }

                if (isset($data['provider']) && !is_numeric($data['provider'])) {
                    $failed[] = [
                        'index' => $index,
                        'provider_instance_id' => $providerInstanceId,
                        'reason' => 'provider 必须为整数',
                    ];
                    continue;
                }

                if (isset($data['price']) && !is_numeric($data['price'])) {
                    $failed[] = [
                        'index' => $index,
                        'provider_instance_id' => $providerInstanceId,
                        'reason' => 'price 必须为数字',
                    ];
                    continue;
                }

                if (isset($data['is_active']) && !in_array($data['is_active'], [0, 1, true, false], true)) {
                    $failed[] = [
                        'index' => $index,
                        'provider_instance_id' => $providerInstanceId,
                        'reason' => 'is_active 必须为布尔值',
                    ];
                    continue;
                }

                $machine = null;
                if (!empty($providerInstanceId)) {
                    $query = Machine::where('provider_instance_id', $providerInstanceId);
                    if ($provider !== null && $provider !== '') {
                        $query->where('provider', $provider);
                    }
                    $machine = $query->first();
                }

                if ($machine) {
                    if (!empty($data['hostname'])) {
                        $exists = Machine::where('hostname', $data['hostname'])
                            ->where('id', '!=', $machine->id)
                            ->exists();
                        if ($exists) {
                            $failed[] = [
                                'index' => $index,
                                'provider_instance_id' => $providerInstanceId,
                                'reason' => 'hostname 已存在',
                            ];
                            continue;
                        }
                    }

                    $machine->update($data);
                    $updated[] = [
                        'id' => $machine->id,
                        'provider_instance_id' => $providerInstanceId,
                    ];
                    continue;
                }

                $requiredFields = ['name', 'hostname', 'ip_address', 'port', 'username'];
                $missing = [];
                foreach ($requiredFields as $field) {
                    if (empty($data[$field])) {
                        $missing[] = $field;
                    }
                }

                if (!empty($missing)) {
                    $failed[] = [
                        'index' => $index,
                        'provider_instance_id' => $providerInstanceId,
                        'reason' => '缺少必填字段: ' . implode(',', $missing),
                    ];
                    continue;
                }

                if (empty($data['password']) && empty($data['private_key'])) {
                    $failed[] = [
                        'index' => $index,
                        'provider_instance_id' => $providerInstanceId,
                        'reason' => '密码和私钥至少需要一个',
                    ];
                    continue;
                }

                $hostnameExists = Machine::where('hostname', $data['hostname'])->exists();
                if ($hostnameExists) {
                    $failed[] = [
                        'index' => $index,
                        'provider_instance_id' => $providerInstanceId,
                        'reason' => 'hostname 已存在',
                    ];
                    continue;
                }

                if (!empty($providerInstanceId)) {
                    $instanceExists = Machine::where('provider_instance_id', $providerInstanceId)
                        ->when($provider !== null && $provider !== '', function ($query) use ($provider) {
                            $query->where('provider', $provider);
                        })
                        ->exists();
                    if ($instanceExists) {
                        $failed[] = [
                            'index' => $index,
                            'provider_instance_id' => $providerInstanceId,
                            'reason' => 'Cloud Provider 实例已存在',
                        ];
                        continue;
                    }
                }

                $data['is_active'] = $data['is_active'] ?? true;
                $data['status'] = $data['status'] ?? 'offline';

                $newMachine = Machine::create($data);
                $created[] = [
                    'id' => $newMachine->id,
                    'provider_instance_id' => $providerInstanceId,
                ];
            }

            DB::commit();

            return $this->ok([
                'created' => $created,
                'updated' => $updated,
                'failed' => $failed,
                'summary' => [
                    'total' => count($items),
                    'created_count' => count($created),
                    'updated_count' => count($updated),
                    'failed_count' => count($failed),
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->error([500, '批量导入失败: ' . $e->getMessage()]);
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

            $machine = Machine::with(['ips' => function ($q) {
                $q->withPivot(['is_primary', 'is_egress', 'bind_status', 'bound_at', 'unbound_at']);
            }])->findOrFail($id);

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

    /**
     * 简单创建云实例（Zenlayer）
     *
     * POST /admin/machine/createSimple
     * Body:
     * {
     *   "provider_id": 1,
     *   "zoneId": "xxx",
     *   "instanceType": "xxx",
     *   "instanceCount": 1,
     *   "eipIds": ["eip-1", "eip-2"],
     *   "name": "xxx"
     * }
     */
    public function createSimple(Request $request)
    {
        $request->validate([
            'provider_id'   => 'required|integer|exists:v2_providers,id',
            'zoneId'        => 'required|string',
            'instanceType'  => 'required|string',
            'name'          => 'required|string|max:255',
            'instanceCount' => 'required|integer|min:1|max:100',
            'eipIds'        => 'required|array|min:1|max:100',
            'eipIds.*'      => 'string',
            'username'      => 'required|string|max:255',
            'port'          => 'nullable|integer|min:1|max:65535',
            'password'      => 'nullable|string',
            'private_key'   => 'nullable|string',
        ]);

        try {
            $driver = CloudProviderManager::makeById((int) $request->input('provider_id'));

            $instanceCount = (int) $request->input('instanceCount');
            $eipIds = $request->input('eipIds', []);

            if (count($eipIds) !== $instanceCount) {
                return $this->error([422, 'instanceCount 必须与 eipIds 数量一致']);
            }

            if (empty($request->input('password')) && empty($request->input('private_key'))) {
                return $this->error([422, '密码和私钥至少需要一个']);
            }

            $params = [
                'zoneId'               => $request->input('zoneId'),
                'imageId'              => 'debian12_20251225',
                'instanceType'         => $request->input('instanceType'),
                'instanceCount'        => $instanceCount,
                'subnetId'             => '1604048104989009153',
                'instanceName'         => $request->input('name'),
                'keyId'                => 'key-RIDRfFik',
                'nicNetworkType'       => 'Auto',
                'systemDisk'           => [
                    'type'        => 'BASIC_NVME_SSD',
                    'size'        => 20,
                    'burstEnable' => false,
                ],
                'dataDisks'            => [],
                'securityGroupId'      => '1604048217236974337',
                'timeZone'             => 'Asia/Shanghai',
                'enableAgent'          => true,
                'enableIpForward'      => true,
                'internetChargeType'   => '',
                'eipBindType'          => 'FullNat',
                'tags'                 => [
                    'tags' => [
                        ['key' => '深圳产品', 'value' => 'NODE'],
                    ],
                ],
                'userData'             => '',
                'marketingOptions'     => [
                    'usePocVoucher' => false,
                ],
                'resourceGroupId'      => 'bebaaa61-ebce-4a7f-8d3b-e11d9afcd459',
            ];

            $overrides = $request->except([
                'provider_id', 'zoneId', 'instanceType', 'instanceCount', 'name', 'eipIds'
            ]);

            if (!empty($overrides)) {
                $params = array_replace_recursive($params, $overrides);
            }

            $result = $driver->createInstance($params);

            $instanceIdSet = $result['data']['instanceIdSet'] ?? [];
            $instanceIds = array_values(
                array_filter(
                    array_keys($instanceIdSet) ?: (is_array($instanceIdSet) ? $instanceIdSet : [])
                )
            );

            if (count($instanceIds) !== $instanceCount) {
                return $this->error([500, '实例创建数量与返回实例ID数量不一致']);
            }

            $instances = $driver->listInstances([
                'instanceIds' => $instanceIds,
                'pageSize' => $instanceCount,
                'page' => 1,
            ]);

            $instanceMap = collect($instances['data'] ?? [])->keyBy('instance_id');
            $bindResults = [];

            foreach ($instanceIds as $index => $instanceId) {
                $instance = $instanceMap->get($instanceId);
                $privateIp = $instance['private_ips'][0] ?? null;

                if (!$privateIp) {
                    return $this->error([500, "实例 {$instanceId} 未获取到内网IP"]);
                }

                $bindResults[] = $driver->bindElasticIp(
                    '1604048104989009153',
                    $eipIds[$index],
                    $privateIp
                );
            }

            $eipInfo = $driver->listElasticIps([
                'eipIds' => $eipIds,
                'pageSize' => $instanceCount,
                'pageNum' => 1,
            ]);
            $eipMap = collect($eipInfo['data'] ?? [])->keyBy('eip_id');

            $items = [];
            foreach ($instanceIds as $index => $instanceId) {
                $instance = $instanceMap->get($instanceId);
                $privateIp = $instance['private_ips'][0] ?? null;
                $nicId = $instance['nic_id'] ?? null;

                $eip = $eipMap->get($eipIds[$index]);
                $publicIp = $eip['ip_address'] ?? null;
                if (is_array($publicIp)) {
                    $publicIp = $publicIp[0] ?? null;
                }

                $items[] = [
                    'name'                 => $request->input('name'),
                    'hostname'             => $instanceId,
                    'ip_address'           => $publicIp ?? $privateIp,
                    'private_ip_address'   => $privateIp,
                    'port'                 => (int) ($request->input('port') ?? 22),
                    'username'             => $request->input('username'),
                    'password'             => $request->input('password'),
                    'private_key'          => $request->input('private_key'),
                    'provider'             => (int) $request->input('provider_id'),
                    'provider_instance_id' => $instanceId,
                    'provider_nic_id'      => $nicId,
                ];
            }

            $importResponse = $this->batchImport(new Request(['items' => $items]));
            $importData = $importResponse->getData(true);

            return $this->ok(array_merge($result, [
                'bind_results' => $bindResults,
                'machine_import' => $importData['data'] ?? $importData,
            ]));
        } catch (OperationNotSupportedException $e) {
            return $this->error([501, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            Log::error('createSimple failed', [
                'provider_id' => $request->input('provider_id'),
                'error' => $e->getMessage(),
            ]);
            return $this->error([500, '创建实例失败: ' . $e->getMessage()]);
        }
    }
}