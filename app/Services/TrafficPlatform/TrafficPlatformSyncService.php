<?php

namespace App\Services\TrafficPlatform;

use App\Exceptions\BusinessException;
use App\Models\TrafficPlatformAccount;
use App\Models\TrafficPlatformSyncJob;

class TrafficPlatformSyncService
{
    /**
     * 同步任务列表查询。
     */
    public function index(array $params): array
    {
        $query = TrafficPlatformSyncJob::query();

        if (!empty($params['platformCode'])) {
            $query->where('platform_code', $params['platformCode']);
        }
        if (!empty($params['accountId'])) {
            $query->where('platform_account_id', $params['accountId']);
        }
        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (!empty($params['startTime'])) {
            $query->where('created_at', '>=', $params['startTime']);
        }
        if (!empty($params['endTime'])) {
            $query->where('created_at', '<=', $params['endTime']);
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $total = $query->count();
        $items = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        $accountIds = $items->pluck('platform_account_id')->unique()->filter()->values();
        $accountMap = TrafficPlatformAccount::whereIn('id', $accountIds)->pluck('account_name', 'id');

        $list = $items->map(function ($item) use ($accountMap) {
            $arr = $item->toArray();
            $arr['account_name'] = $accountMap[$item->platform_account_id] ?? '';
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
     * 同步任务详情查询。
     */
    public function detail(int $id): array
    {
        $job = TrafficPlatformSyncJob::find($id);
        if (!$job) {
            throw new BusinessException([404, '同步任务不存在']);
        }

        $arr = $job->toArray();
        $account = TrafficPlatformAccount::find($job->platform_account_id);
        $arr['account_name'] = $account?->account_name ?? '';

        return $arr;
    }

    /**
     * 根据账号 ID 获取同步账号。
     */
    public function findAccountOrFail(int $accountId): TrafficPlatformAccount
    {
        $account = TrafficPlatformAccount::find($accountId);
        if (!$account) {
            throw new BusinessException([404, '账号不存在']);
        }

        return $account;
    }
}
