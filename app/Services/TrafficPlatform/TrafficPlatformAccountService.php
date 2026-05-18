<?php

namespace App\Services\TrafficPlatform;

use App\Exceptions\BusinessException;
use App\Models\TrafficPlatform;
use App\Models\TrafficPlatformAccount;

class TrafficPlatformAccountService
{
    /**
     * 账号列表查询。
     */
    public function index(array $params): array
    {
        $query = TrafficPlatformAccount::query();

        if (!empty($params['platformCode'])) {
            $query->where('platform_code', $params['platformCode']);
        }
        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $query->where('enabled', $params['enabled']);
        }
        if (!empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('account_name', 'like', "%{$keyword}%")
                    ->orWhere('external_account_id', 'like', "%{$keyword}%");
            });
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $total = $query->count();
        $items = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $platformMap = TrafficPlatform::pluck('name', 'code');
        $list = $items->map(function ($item) use ($platformMap) {
            $arr = $item->toArray();
            $arr['platform_name'] = $platformMap[$item->platform_code] ?? '';
            $arr['credential_masked'] = $item->getMaskedCredential();
            unset($arr['credential_json']);
            return $arr;
        });

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'data' => $list,
        ];
    }

    /**
     * 查询账号详情。
     */
    public function detail(int $id): array
    {
        $account = TrafficPlatformAccount::find($id);
        if (!$account) {
            throw new BusinessException([404, '账号不存在']);
        }

        $arr = $account->toArray();
        $arr['credential_masked'] = $account->getMaskedCredential();
        unset($arr['credential_json']);

        $platform = TrafficPlatform::where('code', $account->platform_code)->first();
        $arr['platform_name'] = $platform?->name ?? '';

        return $arr;
    }

    /**
     * 创建平台账号。
     */
    public function store(array $params): TrafficPlatformAccount
    {
        $platformCode = $params['platformCode'];
        $platform = TrafficPlatform::where('code', $platformCode)->first();
        if (!$platform) {
            throw new BusinessException([422, '平台不存在']);
        }

        return TrafficPlatformAccount::create([
            'platform_id' => $platform->id,
            'platform_code' => $platformCode,
            'account_name' => $params['accountName'],
            'external_account_id' => $params['externalAccountId'] ?? '',
            'credential_json' => $params['credential'],
            'timezone' => $params['timezone'] ?? 'Asia/Shanghai',
            'enabled' => $params['enabled'] ?? 1,
            'balance' => (int) ($params['balance'] ?? 0),
        ]);
    }

    /**
     * 更新平台账号。
     */
    public function update(array $params): TrafficPlatformAccount
    {
        $account = TrafficPlatformAccount::find((int) $params['id']);
        if (!$account) {
            throw new BusinessException([404, '账号不存在']);
        }

        $updateData = [];
        if (array_key_exists('accountName', $params)) {
            $updateData['account_name'] = $params['accountName'];
        }
        if (array_key_exists('externalAccountId', $params)) {
            $updateData['external_account_id'] = $params['externalAccountId'];
        }
        if (array_key_exists('timezone', $params)) {
            $updateData['timezone'] = $params['timezone'];
        }
        if (array_key_exists('enabled', $params)) {
            $updateData['enabled'] = $params['enabled'];
        }
        if (array_key_exists('balance', $params)) {
            $updateData['balance'] = (int) $params['balance'];
        }

        if (array_key_exists('credential', $params) && is_array($params['credential'])) {
            $newCred = $params['credential'];
            $oldCred = $account->credential_json ?? [];

            foreach ($newCred as $key => $value) {
                if ($value === '' || $value === null) {
                    $newCred[$key] = $oldCred[$key] ?? '';
                }
            }
            $updateData['credential_json'] = $newCred;
        }

        if (!empty($updateData)) {
            $account->update($updateData);
        }

        return $account->fresh();
    }

    /**
     * 更新账号启用状态。
     */
    public function updateStatus(int $id, int $enabled): void
    {
        $account = TrafficPlatformAccount::find($id);
        if (!$account) {
            throw new BusinessException([404, '账号不存在']);
        }

        $account->update(['enabled' => $enabled]);
    }

    /**
     * 测试账号连通性。
     */
    public function findForTest(int $id): TrafficPlatformAccount
    {
        $account = TrafficPlatformAccount::find($id);
        if (!$account) {
            throw new BusinessException([404, '账号不存在']);
        }

        return $account;
    }
}
