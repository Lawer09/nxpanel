<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdAccountUpsert;
use App\Http\Requests\Admin\AdAccountBatchAssign;
use App\Models\AdPlatformAccount;
use App\Models\SyncServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdAccountController extends Controller
{
    /**
     * 查询广告账号列表
     * GET /admin/ad-accounts
     */
    public function fetch(Request $request)
    {
        try {
            $page     = (int) $request->query('page', 1);
            $size     = (int) $request->query('size', 20);
            $platform = $request->query('source_platform');
            $status   = $request->query('status');
            $serverId = $request->query('assigned_server_id');
            $keyword  = $request->query('keyword');

            $query = AdPlatformAccount::query();

            if ($platform) {
                $query->where('source_platform', $platform);
            }
            if ($status) {
                $query->where('status', $status);
            }
            if ($serverId) {
                $query->where('assigned_server_id', $serverId);
            }
            if ($keyword) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('account_name', 'like', "%{$keyword}%")
                      ->orWhere('account_label', 'like', "%{$keyword}%")
                      ->orWhere('publisher_id', 'like', "%{$keyword}%");
                });
            }

            $total = $query->count();
            $items = $query->orderByDesc('id')
                ->offset(($page - 1) * $size)
                ->limit($size)
                ->get();

            return $this->ok([
                'page'  => $page,
                'size'  => $size,
                'total' => $total,
                'items' => $items,
            ]);
        } catch (\Exception $e) {
            Log::error('AdAccount fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新建广告账号
     * POST /admin/ad-accounts
     */
    public function save(AdAccountUpsert $request)
    {
        try {
            $params = $request->validated();

            // 唯一性校验：source_platform + account_name
            $exists = AdPlatformAccount::where('source_platform', $params['source_platform'])
                ->where('account_name', $params['account_name'])
                ->exists();
            if ($exists) {
                return $this->error([422, '该平台下账号名称已存在']);
            }

            // assigned_server_id 校验
            if (!empty($params['assigned_server_id'])) {
                if (!SyncServer::where('server_id', $params['assigned_server_id'])->exists()) {
                    return $this->error([422, '分配的服务器不存在']);
                }
            }

            // credentials_json 加密落库（应用层 AES）
            if (isset($params['credentials_json'])) {
                $params['credentials_json'] = $this->encryptCredentials($params['credentials_json']);
            }

            $account = AdPlatformAccount::create($params);

            return $this->ok($account, [201, '创建成功']);
        } catch (\Exception $e) {
            Log::error('AdAccount save error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新广告账号
     * PUT /admin/ad-accounts/{id}
     */
    public function update(AdAccountUpsert $request, int $id)
    {
        try {
            $account = AdPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $params = $request->validated();

            // 唯一性校验（排除自身）
            $exists = AdPlatformAccount::where('source_platform', $params['source_platform'])
                ->where('account_name', $params['account_name'])
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                return $this->error([422, '该平台下账号名称已存在']);
            }

            // assigned_server_id 校验
            if (!empty($params['assigned_server_id'])) {
                if (!SyncServer::where('server_id', $params['assigned_server_id'])->exists()) {
                    return $this->error([422, '分配的服务器不存在']);
                }
            }

            // credentials_json 加密落库
            if (isset($params['credentials_json'])) {
                $params['credentials_json'] = $this->encryptCredentials($params['credentials_json']);
            }

            $account->update($params);

            return $this->ok($account->fresh());
        } catch (\Exception $e) {
            Log::error('AdAccount update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 启用/停用账号
     * PATCH /admin/ad-accounts/{id}/status
     */
    public function updateStatus(Request $request, int $id)
    {
        try {
            $request->validate([
                'status' => 'required|string|in:enabled,disabled',
            ]);

            $account = AdPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $account->update(['status' => $request->input('status')]);

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, '状态格式有误']);
        } catch (\Exception $e) {
            Log::error('AdAccount updateStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 测试账号凭据可用性
     * POST /admin/ad-accounts/{id}/test-credential
     */
    public function testCredential(int $id)
    {
        try {
            $account = AdPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $credentials = $this->decryptCredentials($account->getRawOriginal('credentials_json'));

            // TODO: 根据 source_platform 调用对应平台 SDK 验证凭据
            // 目前返回占位结果
            $valid = !empty($credentials);

            if ($valid) {
                return $this->ok(['message' => '凭据可用']);
            }

            return $this->error([422, '凭据不可用']);
        } catch (\Exception $e) {
            Log::error('AdAccount testCredential error: ' . $e->getMessage());
            return $this->error([422, '凭据验证失败：' . $e->getMessage()]);
        }
    }

    /**
     * 批量分配服务器
     * POST /admin/ad-accounts/batch-assign-server
     */
    public function batchAssignServer(AdAccountBatchAssign $request)
    {
        try {
            $params = $request->validated();

            // 校验目标服务器存在
            if (!SyncServer::where('server_id', $params['assigned_server_id'])->exists()) {
                return $this->error([422, '目标服务器不存在']);
            }

            if (!empty($params['backup_server_id'])) {
                if (!SyncServer::where('server_id', $params['backup_server_id'])->exists()) {
                    return $this->error([422, '备用服务器不存在']);
                }
            }

            DB::beginTransaction();
            try {
                $updateData = [
                    'assigned_server_id' => $params['assigned_server_id'],
                ];
                if (isset($params['backup_server_id'])) {
                    $updateData['backup_server_id'] = $params['backup_server_id'];
                }
                if (isset($params['isolation_group'])) {
                    $updateData['isolation_group'] = $params['isolation_group'];
                }

                AdPlatformAccount::whereIn('id', $params['account_ids'])
                    ->update($updateData);

                DB::commit();
                return $this->ok(true);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('AdAccount batchAssignServer error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    // ── 凭据加解密（应用层 AES） ─────────────────
    private function encryptCredentials(array $credentials): string
    {
        $key = config('app.key');
        $json = json_encode($credentials);
        return encrypt($json);
    }

    private function decryptCredentials(string $encrypted): array
    {
        try {
            $json = decrypt($encrypted);
            return json_decode($json, true) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
