<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Services\MachineSSHService;
use App\Services\CloudProvider\CloudProviderManager;
use App\Services\CloudProvider\OperationNotSupportedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\SshKey;

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
                'hostname'             => 'required|string|max:255',
                'ip_address'           => 'required|string|max:255',
                'private_ip_address'   => 'nullable|string|max:255',
                'port'                 => 'nullable|integer|min:1|max:65535',
                'username'             => 'nullable|string|max:255',
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
                'provider_zone_id'     => 'nullable|string|max:255',
                'ssh_key_id'           => 'nullable|integer|exists:ssh_keys,id',
            ]);

            // 密码和私钥至少需要一个
            if (empty($validated['password']) && empty($validated['private_key']) && empty($validated['ssh_key_id'])) {
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
                'provider_nic_id'      => 'nullable|string|max:255',
                'provider_zone_id'     => 'nullable|string|max:255',
                'ssh_key_id'           => 'nullable|integer|exists:ssh_keys,id',
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
            'provider_zone_id',
            'ssh_key_id',
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

                if (!empty($data['ssh_key_id'])) {
                    $sshKey = SshKey::find((int) $data['ssh_key_id']??0);
                    if (!$sshKey) {
                        $failed[] = [
                            'index' => $index,
                            'provider_instance_id' => $providerInstanceId,
                            'reason' => 'ssh_key_id 无效, ssh_key_id: ' . $data['ssh_key_id'],
                        ];
                        continue;
                    }
                    $data['private_key'] = $sshKey->secret_key;
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
                                'reason' => 'hostname 已存在 machine id: ' . $machine->id,
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

                if (empty($data['password']) && empty($data['private_key']) && empty($data['ssh_key_id'])) {
                    $failed[] = [
                        'index' => $index,
                        'provider_instance_id' => $providerInstanceId,
                        'reason' => '密码、私钥至少需要一个',
                    ];
                    continue;
                }

                $hostnameExists = Machine::where('hostname', $data['hostname'])->exists();
                if ($hostnameExists) {
                    $data['hostname'] = $data['hostname'] . '-' . uniqid();
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

            $sshKeyId = (int) ($machine->ssh_key_id ?? 0);
            if ($sshKeyId > 0) {
                $sshKey = SshKey::find($sshKeyId);
                if (!$sshKey || empty($sshKey->secret_key)) {
                    return $this->error([422, 'ssh_key_id 无效 或 密钥内容为空, ssh_key_id: ' . $sshKeyId]);
                }
                $machine->private_key = $sshKey->secret_key;
            }

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
     */
    public function deployNode(Request $request)
    {
        return $this->error([501, '单机部署接口已废弃']);
    }

    /**
     * 批量部署节点
     */
    public function batchDeploy(Request $request)
    {
        return $this->error([501, '接口已废弃']);
    }

    /**
     * 查询部署任务状态
     */
    public function deployStatus(Request $request)
    {
        return $this->error([501, '接口已废弃']);
    }
  
    /**  
     * 清除节点  
     */  
    public function clearNode(Request $request)  
    {  
        return $this->error([501, '接口已废弃']);
    }

    /**
     * 简单创建云实例（Zenlayer）
     *
     * POST /admin/machine/createSimple
     */
    public function createSimple(Request $request)
    {
        $request->validate([
            'providerId'   => 'required|integer|exists:v2_providers,id',
            'zoneId'        => 'required|string',
            'instanceType'  => 'required|string',
            'name'          => 'nullable|string|max:255',
            'instanceCount' => 'nullable|integer|min:1|max:100',
            'subnetId'      => 'required|string',
            'eips'          => 'required|array|min:1|max:100',
            'eips.*.eipId'  => 'required|string',
            'eips.*.publicIp' => 'nullable|string',
            'sshKeyId'      => 'required|integer|exists:ssh_keys,id',
        ]);

        try {
            $driver = CloudProviderManager::makeById((int) $request->input('providerId'));

            $instanceCount = (int) $request->input('instanceCount') ?? 1;
            $eips = $request->input('eips', []);
            $eipIds = array_values(array_filter(array_map(fn($eip) => $eip['eipId'] ?? null, $eips)));

            if (count($eipIds) !== $instanceCount) {
                return $this->error([422, 'instanceCount 必须与 eips 数量一致']);
            }

            $sshKey = \App\Models\SshKey::findOrFail($request->input('sshKeyId'));

            if (empty($sshKey->provider_key_id)) {
                return $this->error([422, '所选SSH密钥未配置云服务商密钥ID']);
            }

            $params = [
                'zoneId'               => $request->input('zoneId'),
                'imageId'              => 'debian12_20251225',
                'instanceType'         => $request->input('instanceType'),
                'instanceCount'        => $instanceCount,
                'subnetId'             => $request->input('subnetId'),
                'instanceName'         => $request->input('name'),
                'keyId'                => $sshKey->provider_key_id,
                'nicNetworkType'       => 'Auto',
                'systemDisk'           => [
                    'diskCategory'        => 'Basic NVMe SSD',
                    'diskSize'        => 20,
                    'burstingEnabled' => false,
                ],
                'securityGroupId'      => '1604048217236974337',
                'timeZone'             => 'Asia/Shanghai',
                'enableAgent'          => true,
                'enableIpForward'      => true,
                'eipBindType'          => 'FullNat',
                'tags'                 => [
                    'tags' => [
                        ['key' => '深圳产品', 'value' => 'NODE'],
                    ],
                ],
                'marketingOptions'     => [
                    'usePocVoucher' => false,
                ],
                'resourceGroupId'      => 'bebaaa61-ebce-4a7f-8d3b-e11d9afcd459',
            ];

            $overrides = $request->except([
                'providerId', 'zoneId', 'instanceType', 'instanceCount', 'name', 'eips'
            ]);

            if (!empty($overrides)) {
                $params = array_replace_recursive($params, $overrides);
            }

            $result = $driver->createInstance($params);

            $instanceIds = $result['instanceIdSet'] ?? [];

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
                $nicId = $instance['nic_id'] ?? null;

                if (!$privateIp) {
                    return $this->error([500, "实例 {$instanceId} 未获取到内网IP"]);
                }

                $bindResults[] = $driver->bindElasticIp(
                    $nicId,
                    $eipIds[$index],
                    $privateIp
                );
            }

            $eipPublicMap = collect($eips)->mapWithKeys(function ($item) {
                return [$item['eipId'] ?? null => $item['publicIp'] ?? null];
            })->filter(fn($v, $k) => !empty($k))->all();

            $items = [];
            foreach ($instanceIds as $index => $instanceId) {
                $instance = $instanceMap->get($instanceId);
                $privateIp = $instance['private_ips'][0] ?? null;
                $nicId = $instance['nic_id'] ?? null;

                $eipId = $eipIds[$index];
                $publicIp = $eipPublicMap[$eipId] ?? null;
               
                if (is_array($publicIp)) {
                    $publicIp = $publicIp[0] ?? null;
                }

                $items[] = [
                    'name'                 => $request->input('name'),
                    'hostname'             => $instanceId,
                    'ip_address'           => $publicIp ?? $privateIp,
                    'private_ip_address'   => $privateIp,
                    'port'                 => (int) ($request->input('port') ?? 22),
                    'username'             => 'root',
                    'password'             => '',
                    'private_key'          => $sshKey->private_key,
                    'provider'             => (int) $request->input('providerId'),
                    'provider_instance_id' => $instanceId,
                    'provider_nic_id'      => $nicId,
                    'status'               => $instance['status'] ?? '',
                    'os_type'              => $instance['image_name'] ?? '',
                    'cpu_cores'            => $instance['cpu'] ?? 1,
                    'memory'               => $instance['memory'] ?? 1,
                    'disk'                 => $instance['disk'] ?? 20,
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