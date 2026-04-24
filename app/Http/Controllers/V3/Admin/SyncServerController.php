<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncServerFetch;
use App\Http\Requests\Admin\SyncServerSave;
use App\Models\SyncServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SyncServerController extends Controller
{
    /**
     * 查询同步服务器列表
     * GET /admin/sync-servers
     */
    public function fetch(SyncServerFetch $request)
    {
        try {
            $params = $request->validated();

            $query = SyncServer::query();

            if (!empty($params['status'])) {
                $query->where('status', $params['status']);
            }

            $pageSize = $params['page_size'] ?? 20;
            $data = $query->orderByDesc('id')->paginate($pageSize);

            return $this->ok([
                'data'     => $data->items(),
                'total'    => $data->total(),
                'page'     => $data->currentPage(),
                'pageSize' => $data->perPage(),
            ]);
        } catch (\Exception $e) {
            Log::error('SyncServer fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新建/登记服务器
     * POST /admin/sync-servers
     */
    public function save(SyncServerSave $request)
    {
        try {
            $params = $request->validated();

            // server_id 唯一校验
            if (SyncServer::where('server_id', $params['server_id'])->exists()) {
                return $this->error([422, '服务器ID已存在']);
            }

            $params['status'] = SyncServer::STATUS_ONLINE;
            $server = SyncServer::create($params);

            return $this->ok($server, [201, '创建成功']);
        } catch (\Exception $e) {
            Log::error('SyncServer save error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新服务器信息
     * PUT /admin/sync-servers/{server_id}
     */
    public function update(Request $request, string $serverId)
    {
        try {
            $server = SyncServer::where('server_id', $serverId)->first();
            if (!$server) {
                return $this->error([404, '服务器不存在']);
            }

            $validated = $request->validate([
                'server_name'  => 'sometimes|string|max:128',
                'host_ip'      => 'nullable|string|max:64',
                'tags'         => 'nullable|array',
                'capabilities' => 'nullable|array',
            ]);

            $server->update($validated);

            return $this->ok($server->fresh());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, '数据验证失败']);
        } catch (\Exception $e) {
            Log::error('SyncServer update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改节点状态
     * PATCH /admin/sync-servers/{server_id}/status
     */
    public function updateStatus(Request $request, string $serverId)
    {
        try {
            $request->validate([
                'status' => 'required|string|in:online,offline,maintenance',
            ]);

            $server = SyncServer::where('server_id', $serverId)->first();
            if (!$server) {
                return $this->error([404, '服务器不存在']);
            }

            $server->update(['status' => $request->input('status')]);

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, '状态格式有误']);
        } catch (\Exception $e) {
            Log::error('SyncServer updateStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Worker 心跳上报（内部接口）
     * POST /internal/sync-servers/{server_id}/heartbeat
     */
    public function heartbeat(string $serverId)
    {
        try {
            $server = SyncServer::where('server_id', $serverId)->first();

            if (!$server) {
                // 自动注册
                $server = SyncServer::create([
                    'server_id'   => $serverId,
                    'server_name' => $serverId,
                    'status'      => SyncServer::STATUS_ONLINE,
                    'last_heartbeat_at' => now(),
                ]);
            } else {
                $server->update([
                    'status'            => SyncServer::STATUS_ONLINE,
                    'last_heartbeat_at' => now(),
                ]);
            }

            return $this->ok(true);
        } catch (\Exception $e) {
            Log::error('SyncServer heartbeat error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
