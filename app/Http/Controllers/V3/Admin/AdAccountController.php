<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdAccountFetch;
use App\Http\Requests\Admin\AdAccountUpsert;
use App\Http\Requests\Admin\AdAccountBatchAssign;
use App\Http\Resources\AdPlatformAccountResource;
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
    public function fetch(AdAccountFetch $request)
    {
        try {
            $params   = $request->validated();
            $page     = (int) ($params['page'] ?? 1);
            $pageSize = (int) ($params['pageSize'] ?? 20);

            $query = AdPlatformAccount::query();

            if (!empty($params['sourcePlatform'])) {
                $query->where('source_platform', $params['sourcePlatform']);
            }
            if (!empty($params['status'])) {
                $query->where('status', $params['status']);
            }
            if (!empty($params['assignedServerId'])) {
                $query->where('assigned_server_id', $params['assignedServerId']);
            }
            if (!empty($params['keyword'])) {
                $keyword = $params['keyword'];
                $query->where(function ($q) use ($keyword) {
                    $q->where('account_name', 'like', "%{$keyword}%")
                      ->orWhere('account_label', 'like', "%{$keyword}%")
                      ->orWhere('publisher_id', 'like', "%{$keyword}%");
                });
            }

            $total = $query->count();
            $items = $query->orderByDesc('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            return $this->ok([
                'page'     => $page,
                'pageSize' => $pageSize,
                'total'    => $total,
                'data'     => AdPlatformAccountResource::collection($items),
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
            $exists = AdPlatformAccount::where('source_platform', $params['sourcePlatform'])
                ->where('account_name', $params['accountName'])
                ->exists();
            if ($exists) {
                return $this->error([422, '该平台下账号名称已存在']);
            }

            // assigned_server_id 校验
            if (!empty($params['assignedServerId'])) {
                if (!SyncServer::where('server_id', $params['assignedServerId'])->exists()) {
                    return $this->error([422, '分配的服务器不存在']);
                }
            }

            $dbData = [
                'source_platform'    => $params['sourcePlatform'],
                'account_name'       => $params['accountName'],
                'account_label'      => $params['accountLabel'] ?? '',
                'auth_type'          => $params['authType'],
                'credentials_json'   => $params['credentialsJson'],
                'status'             => $params['status'],
                'tags'               => $params['tags'] ?? null,
                'assigned_server_id' => $params['assignedServerId'] ?? '',
                'backup_server_id'   => $params['backupServerId'] ?? '',
                'isolation_group'    => $params['isolationGroup'] ?? '',
                'reporting_timezone' => $params['reportingTimezone'] ?? '',
                'currency_code'      => $params['currencyCode'] ?? '',
                'publisher_id'       => $params['publisherId'] ?? '',
            ];
            $account = AdPlatformAccount::create($dbData);

            return $this->ok(AdPlatformAccountResource::make($account));
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

            // 只保留前端实际传递的字段，避免 nullable 字段被置 null
            $params = array_intersect_key($params, $request->all());

            // 唯一性校验（排除自身）
            $exists = AdPlatformAccount::where('source_platform', $params['sourcePlatform'])
                ->where('account_name', $params['accountName'])
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                return $this->error([422, '该平台下账号名称已存在']);
            }

            // assigned_server_id 校验
            if (!empty($params['assignedServerId'])) {
                if (!SyncServer::where('server_id', $params['assignedServerId'])->exists()) {
                    return $this->error([422, '分配的服务器不存在']);
                }
            }

            $dbData = collect([
                'sourcePlatform'    => 'source_platform',
                'accountName'       => 'account_name',
                'accountLabel'      => 'account_label',
                'authType'          => 'auth_type',
                'credentialsJson'   => 'credentials_json',
                'assignedServerId'  => 'assigned_server_id',
                'backupServerId'    => 'backup_server_id',
                'isolationGroup'    => 'isolation_group',
                'reportingTimezone' => 'reporting_timezone',
                'currencyCode'      => 'currency_code',
                'publisherId'       => 'publisher_id',
            ])->mapWithKeys(fn ($col, $key) => isset($params[$key]) ? [$col => $params[$key]] : [])
              ->merge(collect($params)->only(['status', 'tags']))
              ->toArray();

            $account->update($dbData);

            return $this->ok(AdPlatformAccountResource::make($account->fresh()));
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

            $credentials = $account->credentials_json;

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
            if (!SyncServer::where('server_id', $params['assignedServerId'])->exists()) {
                return $this->error([422, '目标服务器不存在']);
            }

            if (!empty($params['backupServerId'])) {
                if (!SyncServer::where('server_id', $params['backupServerId'])->exists()) {
                    return $this->error([422, '备用服务器不存在']);
                }
            }

            DB::beginTransaction();
            try {
                $updateData = [
                    'assigned_server_id' => $params['assignedServerId'],
                ];
                if (isset($params['backupServerId'])) {
                    $updateData['backup_server_id'] = $params['backupServerId'];
                }
                if (isset($params['isolationGroup'])) {
                    $updateData['isolation_group'] = $params['isolationGroup'];
                }

                AdPlatformAccount::whereIn('id', $params['accountIds'])
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

}
