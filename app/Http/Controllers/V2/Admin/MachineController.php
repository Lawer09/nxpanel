<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use Illuminate\Http\Request;
use App\Services\MachineSSHService;

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

            return response()->json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'data' => $data,
                    'total' => $total,
                    'pageSize' => $pageSize,
                    'page' => $page
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 1,
                'msg' => $e->getMessage()
            ]);
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
                return response()->json([
                    'code' => 1,
                    'msg' => '密码和私钥至少需要一个'
                ]);
            }

            $validated['is_active'] = $validated['is_active'] ?? true;
            $validated['status'] = 'offline';

            $machine = Machine::create($validated);

            return response()->json([
                'code' => 0,
                'msg' => '机器创建成功',
                'data' => $machine->makeHidden(['password', 'private_key'])
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 1,
                'msg' => '数据验证失败',
                'errors' => $e->errors()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 1,
                'msg' => $e->getMessage()
            ]);
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
                return response()->json([
                    'code' => 1,
                    'msg' => '机器ID不能为空'
                ]);
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

            return response()->json([
                'code' => 0,
                'msg' => '机器更新成功',
                'data' => $machine->makeHidden(['password', 'private_key'])
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'code' => 1,
                'msg' => '数据验证失败',
                'errors' => $e->errors()
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
     * 删除机器
     */
    public function drop(Request $request)
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
            $machine->delete();

            return response()->json([
                'code' => 0,
                'msg' => '机器删除成功'
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
     * 获取机器详情
     */
    public function detail(Request $request)
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

            return response()->json([
                'code' => 0,
                'msg' => 'success',
                'data' => $machine->makeHidden(['password', 'private_key'])
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
     * 测试SSH连接  
     */  
    public function testConnection(Request $request)  
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
            $ssh = $service->connect($machine);  
  
            $osInfo = trim($ssh->exec('uname -a') ?? '');  
            $ssh->disconnect();  
  
            $machine->update([  
                'status' => 'online',  
                'last_check_at' => now()  
            ]);  
  
            return response()->json([  
                'code' => 0,  
                'msg' => '连接测试成功',  
                'data' => [  
                    'status' => 'online',  
                    'os_info' => $osInfo  
                ]  
            ]);  
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {  
            return response()->json([  
                'code' => 1,  
                'msg' => '机器不存在'  
            ]);  
        } catch (\Illuminate\Validation\ValidationException $e) {  
            return response()->json([  
                'code' => 1,  
                'msg' => '参数验证失败',  
                'errors' => $e->errors()  
            ]);  
        } 
        catch (\Illuminate\Contracts\Encryption\DecryptException $e) {  
            return response()->json([  
                'code' => 1,  
                'msg' => '密码或私钥解密失败，请重新编辑该机器并保存密码/私钥'  
            ]);  
        }
        catch (\Exception $e) {  
            // 连接异常（网络不通、端口不通等）  
            if (isset($machine) && $machine) {  
                $machine->update([
                    'status' => 'error',  
                    'last_check_at' => now()  
                ]);  
            }  
            return response()->json([  
                'code' => 1,  
                'msg' => 'SSH连接失败: ' . $e->getMessage()  
            ]);  
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
                return response()->json([
                    'code' => 1,
                    'msg' => '请选择要删除的机器'
                ]);
            }

            Machine::whereIn('id', $ids)->delete();

            return response()->json([
                'code' => 0,
                'msg' => '批量删除成功'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => 1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    /**  
     * 部署节点  
     */  
    public function deployNode(Request $request)  
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
            $result = $service->executeScript($machine, 'node-install.sh');  
  
            // 根据执行结果更新机器状态  
            if ($result['exit_code'] === 0) {  
                $machine->update([  
                    'status' => 'online',  
                    'last_check_at' => now()  
                ]);  
            }  
  
            return response()->json([  
                'code' => $result['exit_code'] === 0 ? 0 : 1,  
                'msg' => $result['exit_code'] === 0 ? '节点部署完成' : '节点部署失败',  
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