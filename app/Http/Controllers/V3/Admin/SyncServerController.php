<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncServerFetch;
use App\Http\Requests\Admin\SyncServerSave;
use App\Http\Requests\Admin\SyncServerUpdate;
use App\Http\Resources\SyncServerResource;
use App\Models\SyncServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

            $pageSize = $params['pageSize'] ?? 20;
            $data = $query->orderByDesc('id')->paginate($pageSize);

            return $this->ok([
                'data'     => SyncServerResource::collection($data->items()),
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
            if (SyncServer::where('server_id', $params['serverId'])->exists()) {
                return $this->error([422, '服务器ID已存在']);
            }

            $dbData = [
                'server_id'   => $params['serverId'],
                'server_name' => $params['serverName'],
                'host_ip'     => $params['hostIp'] ?? '',
                'secret_key'  => $params['secretKey'] ?? '',
                'port'        => $params['port'] ?? 8080,
                'tags'        => $params['tags'] ?? null,
                'capabilities' => $params['capabilities'] ?? null,
                'status'      => SyncServer::STATUS_ONLINE,
            ];
            $server = SyncServer::create($dbData);

            return $this->ok(SyncServerResource::make($server), [201, '创建成功']);
        } catch (\Exception $e) {
            Log::error('SyncServer save error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新服务器信息
     * PUT /admin/sync-servers/{server_id}
     */
    public function update(SyncServerUpdate $request, string $serverId)
    {
        try {
            $server = SyncServer::where('server_id', $serverId)->first();
            if (!$server) {
                return $this->error([404, '服务器不存在']);
            }

            $params = $request->validated();
            $dbData = collect([
                'serverName' => 'server_name',
                'hostIp'     => 'host_ip',
                'secretKey'  => 'secret_key',
            ])->mapWithKeys(fn ($col, $key) => isset($params[$key]) ? [$col => $params[$key]] : [])
              ->merge(collect($params)->only(['port', 'tags', 'capabilities']))
              ->toArray();
            $server->update($dbData);

            return $this->ok(SyncServerResource::make($server->fresh()));
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
     * 测试拉取：向同步服务器发起 trigger 请求
     * POST /admin/sync-servers/{server_id}/test-sync
     */
    public function testSync(string $serverId)
    {
        try {
            $server = SyncServer::where('server_id', $serverId)->first();
            if (!$server) {
                return $this->error([404, '服务器不存在']);
            }

            if (empty($server->host_ip)) {
                return $this->error([422, '服务器未配置 host_ip']);
            }
            if (empty($server->secret_key)) {
                return $this->error([422, '服务器未配置 secret_key']);
            }

            $port = $server->port ?: 8080;
            $url  = "http://{$server->host_ip}:{$port}/api/sync/trigger";

            $response = Http::timeout(10)->post($url, [
                'key' => $server->secret_key,
            ]);

            return $this->ok([
                'url'        => $url,
                'httpStatus' => $response->status(),
                'body'       => $response->json() ?? $response->body(),
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return $this->error([504, '连接超时: ' . $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('SyncServer testSync error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 按日期范围同步收入数据
     * POST /admin/sync-servers/{server_id}/sync-revenue
     * Query: start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     */
    public function syncRevenueByDate(Request $request, string $serverId)
    {
        try {
            $request->validate([
                'start_date' => 'required|date_format:Y-m-d',
                'end_date'   => 'required|date_format:Y-m-d|after_or_equal:start_date',
            ]);

            $server = SyncServer::where('server_id', $serverId)->first();
            if (!$server) {
                return $this->error([404, '服务器不存在']);
            }

            if (empty($server->host_ip)) {
                return $this->error([422, '服务器未配置 host_ip']);
            }
            if (empty($server->secret_key)) {
                return $this->error([422, '服务器未配置 secret_key']);
            }

            $port = $server->port ?: 8080;
            $url  = "http://{$server->host_ip}:{$port}/api/sync/revenue";

            $response = Http::timeout(30)
                ->withHeaders(['Authorization' => $server->secret_key])
                ->post($url, [
                    'start_date' => $request->input('start_date'),
                    'end_date'   => $request->input('end_date'),
                ]);

            return $this->ok([
                'url'        => $url,
                'httpStatus' => $response->status(),
                'body'       => $response->json() ?? $response->body(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, '日期格式有误']);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return $this->error([504, '连接超时: ' . $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('SyncServer syncRevenueByDate error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
