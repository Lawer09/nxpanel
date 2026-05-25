<?php

namespace App\Services;

use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Utils\Helper;

class InviteService
{
    /**
     * 生成邀请码。
     */
    public function createCode(int $userId): array
    {
        $unusedCount = InviteCode::query()
            ->where('user_id', $userId)
            ->where('status', InviteCode::STATUS_UNUSED)
            ->count();

        if ($unusedCount >= (int) admin_setting('invite_gen_limit', 5)) {
            return [
                'ok' => false,
                'error' => [400, __('The maximum number of creations has been reached')],
            ];
        }

        $inviteCode = new InviteCode();
        $inviteCode->user_id = $userId;
        $inviteCode->code = Helper::randomChar(8);

        return [
            'ok' => true,
            'data' => [
                'created' => (bool) $inviteCode->save(),
                'code' => $inviteCode->code,
            ],
        ];
    }

    /**
     * 获取邀请统计。
     */
    public function summary(int $userId): ?array
    {
        $user = User::query()
            ->find($userId)
            ?->load(['codes' => fn($query) => $query->where('status', InviteCode::STATUS_UNUSED)]);

        if (!$user) {
            return null;
        }

        $commissionRate = (int) admin_setting('invite_commission', 10);
        if ($user->commission_rate) {
            $commissionRate = (int) $user->commission_rate;
        }

        $pendingCommission = (int) Order::query()
            ->where('status', 3)
            ->where('commission_status', 0)
            ->where('invite_user_id', $user->id)
            ->sum('commission_balance');

        if ((int) admin_setting('commission_distribution_enable', 0)) {
            $pendingCommission = (int) round($pendingCommission * ((float) admin_setting('commission_distribution_l1') / 100));
        }

        return [
            'codes' => $user->codes,
            'summary' => [
                'invitedUsers' => (int) User::query()->where('invite_user_id', $user->id)->count(),
                'totalCommission' => (int) CommissionLog::query()->where('invite_user_id', $user->id)->sum('get_amount'),
                'pendingCommission' => $pendingCommission,
                'commissionRate' => $commissionRate,
                'availableCommission' => (int) $user->commission_balance,
            ],
        ];
    }

    /**
     * 获取返佣明细分页。
     */
    public function commissions(int $userId, int $page, int $pageSize): array
    {
        $query = CommissionLog::query()
            ->where('invite_user_id', $userId)
            ->where('get_amount', '>', 0)
            ->orderByDesc('created_at');

        $total = $query->count();
        $items = $query->forPage($page, $pageSize)->get();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }
}
