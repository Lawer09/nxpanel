<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CamelizeResource;
use App\Models\TrafficPlatform;
use App\Models\TrafficPlatformAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TrafficPlatformAccountController extends Controller
{
    /**
     * 账号列表
     * GET /traffic-platform/accounts
     */
    public function fetch(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'platformCode' => 'nullable|string|max:50',
                'enabled'      => 'nullable|integer|in:0,1',
                'keyword'      => 'nullable|string|max:100',
                'page'         => 'nullable|integer|min:1',
                'pageSize'     => 'nullable|integer|min:1|max:200',
            ]);

            $query = TrafficPlatformAccount::query();

            if ($request->filled('platformCode')) {
                $query->where('platform_code', $request->input('platformCode'));
            }
            if ($request->filled('enabled')) {
                $query->where('enabled', $request->input('enabled'));
            }
            if ($request->filled('keyword')) {
                $keyword = $request->input('keyword');
                $query->where(function ($q) use ($keyword) {
                    $q->where('account_name', 'like', "%{$keyword}%")
                      ->orWhere('external_account_id', 'like', "%{$keyword}%");
                });
            }

            $page     = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 20);

            $total = $query->count();
            $items = $query->orderByDesc('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            // 补充平台名称
            $platformMap = TrafficPlatform::pluck('name', 'code');
            $list = $items->map(function ($item) use ($platformMap) {
                $arr = $item->toArray();
                $arr['platform_name'] = $platformMap[$item->platform_code] ?? '';
                $arr['credential_masked'] = $item->getMaskedCredential();
                unset($arr['credential_json']);
                return $arr;
            });

            return $this->ok([
                'page'     => $page,
                'pageSize' => $pageSize,
                'total'    => $total,
                'data'     => CamelizeResource::collection($list),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 账号详情
     * GET /traffic-platform/accounts/{id}
     */
    public function detail(int $id): JsonResponse
    {
        try {
            $account = TrafficPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $arr = $account->toArray();
            $arr['credential_masked'] = $account->getMaskedCredential();
            unset($arr['credential_json']);

            $platform = TrafficPlatform::where('code', $account->platform_code)->first();
            $arr['platform_name'] = $platform?->name ?? '';

            return $this->ok(CamelizeResource::make($arr));
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount detail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增账号
     * POST /traffic-platform/accounts
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'platformCode'      => 'required|string|max:50',
                'accountName'       => 'required|string|max:100',
                'externalAccountId' => 'nullable|string|max:100',
                'credential'        => 'required|array',
                'timezone'          => 'nullable|string|max:64',
                'enabled'           => 'nullable|integer|in:0,1',
            ]);

            $platformCode = $request->input('platformCode');
            $platform = TrafficPlatform::where('code', $platformCode)->first();
            if (!$platform) {
                return $this->error([422, '平台不存在']);
            }

            $account = TrafficPlatformAccount::create([
                'platform_id'         => $platform->id,
                'platform_code'       => $platformCode,
                'account_name'        => $request->input('accountName'),
                'external_account_id' => $request->input('externalAccountId', ''),
                'credential_json'     => $request->input('credential'),
                'timezone'            => $request->input('timezone', 'Asia/Shanghai'),
                'enabled'             => $request->input('enabled', 1),
            ]);

            return $this->ok(CamelizeResource::make($account));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount save error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改账号
     * PUT /traffic-platform/accounts/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $account = TrafficPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $request->validate([
                'accountName'       => 'nullable|string|max:100',
                'externalAccountId' => 'nullable|string|max:100',
                'credential'        => 'nullable|array',
                'timezone'          => 'nullable|string|max:64',
                'enabled'           => 'nullable|integer|in:0,1',
            ]);

            $updateData = [];
            if ($request->has('accountName'))       $updateData['account_name']        = $request->input('accountName');
            if ($request->has('externalAccountId')) $updateData['external_account_id'] = $request->input('externalAccountId');
            if ($request->has('timezone'))          $updateData['timezone']            = $request->input('timezone');
            if ($request->has('enabled'))           $updateData['enabled']             = $request->input('enabled');

            // credential: 合并更新，空值字段不覆盖
            if ($request->filled('credential')) {
                $newCred = $request->input('credential');
                $oldCred = $account->credential_json ?? [];

                foreach ($newCred as $key => $value) {
                    if ($value === '' || $value === null) {
                        // 空值保留原值
                        $newCred[$key] = $oldCred[$key] ?? '';
                    }
                }
                $updateData['credential_json'] = $newCred;
            }

            $account->update($updateData);

            return $this->ok(CamelizeResource::make($account->fresh()));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 启用/禁用账号
     * PATCH /traffic-platform/accounts/{id}/status
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'enabled' => 'required|integer|in:0,1',
            ]);

            $account = TrafficPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $account->update(['enabled' => $request->input('enabled')]);

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount updateStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 测试账号连接
     * POST /traffic-platform/accounts/{id}/test
     *
     * 转发给 Go 内部接口
     */
    public function test(int $id): JsonResponse
    {
        try {
            $account = TrafficPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $goUrl = 'http://47.254.131.223:8080/api/traffic-platform/accounts/' . $id . '/test';

            $response = \Illuminate\Support\Facades\Http::timeout(15)->post($goUrl);

            if ($response->successful()) {
                $body = $response->json();
                return $this->ok($body['data'] ?? $body);
            }

            return $this->error([502, '测试连接失败: ' . $response->body()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAccount test error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
