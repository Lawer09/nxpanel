<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\IpPool;
use App\Services\CloudProvider\CloudProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MachineIpController extends Controller
{
    /**
     * 切换机器绑定的弹性IP - 新优化逻辑
     * 
     * 处理流程（先保证新IP成功，再移除旧IP）：
     * 1. 绑定新IP并设置出口IP - 失败直接返回错误
     * 2. 成功后才解除旧绑定 - 失败仅记录警告，不阻断
     * 3. 更新数据库关联关系
     * 
     * POST /api/v3/{secure_path}/machine/switchIp
     * {
     *   "machine_id": 1,
     *   "ip_id": 2,
     *   "set_as_primary": true,
     *   "set_as_egress": true
     * }
     */
    public function switchIp(Request $request)
    {
        $request->validate([
            'machineId' => 'required|integer|exists:machines,id',
            'ipId' => 'required|integer|exists:v2_ip_pool,id',
            'setAsPrimary' => 'nullable|boolean',
            'setAsEgress' => 'nullable|boolean',
        ]);

        $machine = Machine::with(['ips' => function($query) {
            $query->wherePivot('is_primary', true)->wherePivot('bind_status', 'active');
        }])->findOrFail($request->integer('machineId'));
        
        $ip = IpPool::findOrFail($request->integer('ipId'));
        $setAsPrimary = $request->boolean('setAsPrimary', true);
        $setAsEgress = $request->boolean('setAsEgress', true);

        try {
            $driver = null;
            $oldPrimaryIp = $machine->primaryIp;
            $warnings = [];

            // ========== 第一步：绑定新IP并设置出口IP ==========
            if ($machine->provider && $machine->provider_nic_id && $ip->provider_ip_id && $machine->private_ip_address) {
                
                $elasticIpId = $ip->provider_ip_id;
                $nicId = $machine->provider_nic_id;
                $privateIpAddress = $machine->private_ip_address;

                try {
                    $driver = CloudProviderManager::makeById((int) $machine->provider);
                    
                    // 绑定新IP到云服务商
                    $bindResult = $driver->bindElasticIp(
                        $nicId,
                        $elasticIpId,
                        $privateIpAddress   
                    );

                    Log::info('Step 1: New elastic IP bound via cloud API', [
                        'machine_id' => $machine->id,
                        'newIp' => $ip->ip,
                        'nicId' => $nicId,
                        'result' => $bindResult,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Step 1: Failed to bind new IP via cloud API', [
                        'machineId' => $machine->id,
                        'newIp' => $ip->ip,
                        'nicId' => $nicId,
                        'error' => $e->getMessage(),
                    ]);
                    // 绑定新IP失败直接返回错误
                    return $this->error([500, '绑定新IP失败: ' . $e->getMessage()]);
                }

                // 设置新IP为出口IP（如果指定）
                $isEgressSet = false;
                if ($setAsEgress) {
                    try {
                        // 调用云服务商API设置出口IP
                        $egressResult = $driver->configEipEgress($elasticIpId);
                        
                        $isEgressSet = true;
                        
                        Log::info('Step 1: New IP set as egress IP successfully', [
                            'machine_id' => $machine->id,
                            'ip' => $ip->ip,
                            'eipId' => $elasticIpId,
                            'result' => $egressResult,
                        ]);
                        $machine->update(['ip_address' => $ip->ip]);
                    } catch (\App\Exceptions\OperationNotSupportedException $e) {
                        Log::warning('Step 1: Cloud provider does not support egress IP configuration', [
                            'machineId' => $machine->id,
                            'error' => $e->getMessage(),
                        ]);
                        // 不支持此操作，不影响绑定成功状态
                        $warnings[] = '设置出口IP失败：云服务商不支持此操作';
                    } catch (\Exception $e) {
                        Log::warning('Step 1: Failed to set egress IP', [
                            'machine_id' => $machine->id,
                            'ip' => $ip->ip,
                            'error' => $e->getMessage(),
                        ]);
                        // 设置出口IP失败，不影响绑定成功状态
                        $warnings[] = '设置出口IP失败：' . $e->getMessage();
                    }
                }

                // 第一步成功：更新数据库中的新IP绑定关系
                $existingBind = $machine->ips()->where('ip_id', $ip->id)->first();
                
                if ($existingBind) {
                    $machine->ips()->updateExistingPivot($ip->id, [
                        'bind_status' => 'active',
                        'is_primary' => $setAsPrimary,
                        'is_egress' => $isEgressSet,
                        'bound_at' => now(),
                        'unbound_at' => null,
                    ]);
                } else {
                    $machine->ips()->attach($ip->id, [
                        'is_primary' => $setAsPrimary,
                        'is_egress' => $isEgressSet,
                        'bind_status' => 'active',
                        'bound_at' => now(),
                    ]);
                }

                // 如果设置为主IP，将其他IP设为非主IP
                if ($setAsPrimary) {
                    $machine->ips()
                        ->where('ip_id', '!=', $ip->id)
                        ->update(['ip_machine.is_primary' => false]);
                }

                // 如果设置为出口IP，将其他IP设为非出口IP
                if ($isEgressSet) {
                    $machine->ips()
                        ->where('ip_id', '!=', $ip->id)
                        ->update(['ip_machine.is_egress' => false]);
                }

                Log::info('Step 1: New IP association created/updated in database', [
                    'machine_id' => $machine->id,
                    'new_ip_id' => $ip->id,
                    'is_primary' => $setAsPrimary,
                    'is_egress' => $isEgressSet,
                ]);

                // ========== 第二步：新IP成功后才解除旧绑定 ==========
                if ($oldPrimaryIp && $oldPrimaryIp->id != $ip->id) {
                    try {
                        $oldElasticIpId = $oldPrimaryIp->provider_ip_id;
                        // 调用云API解除旧IP
                        if ($oldElasticIpId){
                            $driver->unbindElasticIp(
                                $oldElasticIpId
                            );
                        } else {
                            $warnings[] = '旧IP缺少云服务商侧IP ID，无法自动解绑，请手动解绑';
                            Log::info('Step 2: Old primary IP unbound from cloud API', [
                                'machine_id' => $machine->id,
                                'old_ip' => $oldPrimaryIp->ip,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Step 2: Failed to unbind old IP, but new IP is already active', [
                            'machine_id' => $machine->id,
                            'old_ip' => $oldPrimaryIp->ip,
                            'error' => $e->getMessage(),
                        ]);
                        // 解绑旧IP失败仅记录警告，不阻断流程
                        $warnings[] = '解绑旧IP失败：' . $e->getMessage();
                    }

                    // 更新数据库：设旧IP为非活跃
                    $machine->ips()->updateExistingPivot($oldPrimaryIp->id, [
                        'bind_status' => 'inactive',
                        'is_primary' => false,
                        'is_egress' => false,
                        'unbound_at' => now(),
                    ]);
                    
                    Log::info('Step 2: Old IP association set to inactive in database', [
                        'machine_id' => $machine->id,
                        'old_ip_id' => $oldPrimaryIp->id,
                    ]);
                }
            } else {
                return $this->error([422, '机器未配置云服务商或网卡ID']);
            }

            return $this->ok([
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
                'ip' => $ip->ip,
                'is_primary' => $setAsPrimary,
                'is_egress' => $setAsEgress,
                'warnings' => $warnings,
                'message' => empty($warnings) ? '切换IP成功' : ('切换IP成功，部分操作失败：' . implode('；', $warnings)),
            ]);
        } catch (\Exception $e) {
            Log::error('Switch IP failed', [
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error([500, '切换IP失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 配置弹性IP作为出口IP（Zenlayer ConfigEipEgressIp）
     * 
     * POST /api/v2/{secure_path}/machine/configEipEgress
     * {
     *   "machine_id": 1,
     *   "eip_id": "xxx"  // Zenlayer EIP ID
     * }
     */
    public function configEipEgress(Request $request)
    {
        $request->validate([
            'machine_id' => 'required|integer|exists:machines,id',
            'eip_id' => 'required|string',
        ]);

        $machine = Machine::findOrFail($request->integer('machine_id'));

        // 检查机器是否配置了云服务商
        if (!$machine->provider) {
            return $this->error([422, '机器未配置云服务商']);
        }

        try {
            $driver = CloudProviderManager::makeById((int) $machine->provider);

            // 调用云服务商API设置出口IP
            $result = $driver->configEipEgress($request->input('eip_id'));

            Log::info('EIP egress configured', [
                'machine_id' => $machine->id,
                'eip_id' => $request->input('eip_id'),
                'result' => $result,
            ]);

            return $this->ok([
                'machine_id' => $machine->id,
                'eip_id' => $request->input('eip_id'),
                'request_id' => $result['requestId'] ?? null,
            ]);
        } catch (\App\Exceptions\OperationNotSupportedException $e) {
            return $this->error([501, '当前云服务商不支持此操作']);
        } catch (\Exception $e) {
            Log::error('Config EIP egress failed', [
                'machine_id' => $machine->id,
                'eip_id' => $request->input('eip_id'),
                'error' => $e->getMessage(),
            ]);

            // 解析云服务商错误码
            $errorMsg = $this->parseCloudError($e);
            return $this->error([500, $errorMsg]);
        }
    }

    /**
     * 解析云服务商错误信息
     */
    private function parseCloudError(\Exception $e): string
    {
        $message = $e->getMessage();

        // Zenlayer 错误码映射
        $errorMap = [
            'INVALID_EIP_NOT_FOUND' => 'EIP不存在',
            'OPERATION_DENIED_EIP_IS_GROUP' => '三线IP无法操作',
            'OPERATION_DENIED_EIP_NOT_ASSIGNED' => 'EIP状态未绑定',
        ];

        foreach ($errorMap as $code => $msg) {
            if (str_contains($message, $code)) {
                return $msg;
            }
        }

        return '配置出口IP失败: ' . $message;
    }

    /**
     * 获取机器的弹性IP列表
     * 
     * GET /api/v2/{secure_path}/machine/elasticIps?machine_id=1
     */
    public function getElasticIps(Request $request)
    {
        $request->validate([
            'machine_id' => 'required|integer|exists:machines,id',
        ]);

        $machine = Machine::with(['ips' => function($query) {
            $query->wherePivot('bind_status', 'active');
        }])->findOrFail($request->integer('machine_id'));

        if (!$machine->provider || !$machine->provider_instance_id) {
            return $this->ok([
                'bound_ips' => $machine->ips->map(function($ip) {
                    return [
                        'id' => $ip->id,
                        'ip' => $ip->ip,
                        'bandwidth' => $ip->bandwidth,
                        'is_primary' => $ip->pivot->is_primary,
                        'bind_status' => $ip->pivot->bind_status,
                        'bound_at' => $ip->pivot->bound_at,
                    ];
                }),
                'available_ips' => [],
            ]);
        }

        try {
            $driver = CloudProviderManager::makeById((int) $machine->provider);
            
            // 获取实例绑定的弹性IP
            $instanceIps = $driver->getInstanceElasticIps($machine->provider_instance_id);
            
            // 获取账号下所有可用的弹性IP
            $allIps = $driver->listElasticIps();

            return $this->ok([
                'bound_ips' => $machine->ips->map(function($ip) {
                    return [
                        'id' => $ip->id,
                        'ip' => $ip->ip,
                        'bandwidth' => $ip->bandwidth,
                        'is_primary' => $ip->pivot->is_primary,
                        'bind_status' => $ip->pivot->bind_status,
                        'bound_at' => $ip->pivot->bound_at,
                    ];
                }),
                'instance_ips' => $instanceIps,
                'available_ips' => $allIps,
            ]);
        } catch (\Exception $e) {
            Log::error('Get elastic IPs failed', [
                'machine_id' => $machine->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error([500, '获取弹性IP列表失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 绑定IP到机器（不通过云API，仅数据库操作）
     * 
     * POST /api/v3/{secure_path}/machine/bindIp
     * {
     *   "machine_id": 1,
     *   "ip_id": 2,
     *   "is_primary": true
     * }
     */
    public function bindIp(Request $request)
    {
        $request->validate([
            'machine_id' => 'required|integer|exists:machines,id',
            'ip_id' => 'required|integer|exists:v2_ip_pool,id',
            'is_primary' => 'nullable|boolean',
            'is_egress' => 'nullable|boolean',
        ]);

        $machine = Machine::findOrFail($request->integer('machine_id'));
        $ip = IpPool::findOrFail($request->integer('ip_id'));
        $isPrimary = $request->boolean('is_primary', false);
        $isEgress = $request->boolean('is_egress', false);

        try {
            // 检查是否已存在绑定
            $existingBind = $machine->ips()->where('ip_id', $ip->id)->first();
            
            if ($existingBind) {
                // 已存在，更新状态
                $machine->ips()->updateExistingPivot($ip->id, [
                    'bind_status' => 'active',
                    'is_primary' => $isPrimary,
                    'is_egress' => $isEgress,
                    'bound_at' => now(),
                    'unbound_at' => null,
                ]);
            } else {
                // 不存在，创建新绑定
                $machine->ips()->attach($ip->id, [
                    'is_primary' => $isPrimary,
                    'is_egress' => $isEgress,
                    'bind_status' => 'active',
                    'bound_at' => now(),
                ]);
            }

            // 如果设置为主IP，将其他IP设为非主IP
            if ($isPrimary) {
                $machine->ips()
                    ->where('ip_id', '!=', $ip->id)
                    ->update(['ip_machine.is_primary' => false]);
            }

            if ($isEgress) {
                $machine->ips()
                    ->where('ip_id', '!=', $ip->id)
                    ->update(['ip_machine.is_egress' => false]);
            }

            Log::info('IP bound to machine', [
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
                'is_primary' => $isPrimary,
            ]);

            return $this->ok([
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
                'ip' => $ip->ip,
                'is_primary' => $isPrimary,
                'is_egress' => $isEgress,
            ]);
        } catch (\Exception $e) {
            Log::error('Bind IP failed', [
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error([500, '绑定IP失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 解绑IP（不通过云API，仅数据库操作）
     * 
     * POST /api/v3/{secure_path}/machine/unbindIp
     * {
     *   "machine_id": 1,
     *   "ip_id": 2
     * }
     */
    public function unbindIp(Request $request)
    {
        $request->validate([
            'machine_id' => 'required|integer|exists:machines,id',
            'ip_id' => 'required|integer|exists:v2_ip_pool,id',
        ]);

        $machine = Machine::findOrFail($request->integer('machine_id'));
        $ip = IpPool::findOrFail($request->integer('ip_id'));

        try {
            // 检查绑定是否存在
            $existingBind = $machine->ips()->where('ip_id', $ip->id)->first();
            
            if (!$existingBind) {
                return $this->error([404, 'IP未绑定到该机器']);
            }

            // 更新为非活跃状态
            $machine->ips()->updateExistingPivot($ip->id, [
                'bind_status' => 'inactive',
                'is_primary' => false,
                'is_egress' => false,
                'unbound_at' => now(),
            ]);


            Log::info('IP unbound from machine', [
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
            ]);

            return $this->ok([
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Unbind IP failed', [
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error([500, '解绑IP失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 设置主IP
     * 
     * POST /api/v3/{secure_path}/machine/setPrimaryIp
     * {
     *   "machine_id": 1,
     *   "ip_id": 2
     * }
     */
    public function setPrimaryIp(Request $request)
    {
        $request->validate([
            'machine_id' => 'required|integer|exists:machines,id',
            'ip_id' => 'required|integer|exists:v2_ip_pool,id',
        ]);

        $machine = Machine::findOrFail($request->integer('machine_id'));
        $ip = IpPool::findOrFail($request->integer('ip_id'));

        try {
            // 检查绑定是否存在且活跃
            $existingBind = $machine->ips()
                ->where('ip_id', $ip->id)
                ->wherePivot('bind_status', 'active')
                ->first();
            
            if (!$existingBind) {
                return $this->error([404, 'IP未绑定到该机器或已失效']);
            }

            // 将所有IP设为非主IP
            $machine->ips()->update(['ip_machine.is_primary' => false]);

            // 设置当前IP为主IP
            $machine->ips()->updateExistingPivot($ip->id, [
                'is_primary' => true,
            ]);

            Log::info('Primary IP set', [
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
            ]);

            return $this->ok([
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
                'ip' => $ip->ip,
            ]);
        } catch (\Exception $e) {
            Log::error('Set primary IP failed', [
                'machine_id' => $machine->id,
                'ip_id' => $ip->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error([500, '设置主IP失败: ' . $e->getMessage()]);
        }
    }
}
