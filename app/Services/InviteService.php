<?php

namespace App\Services;

use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

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
            'invitedUsers' => (int) User::query()->where('invite_user_id', $user->id)->count(),
        ];
    }

    /**
     * 获取返佣明细分页。
     */
    public function commissions(int $userId, int $page, int $pageSize): array
    {
        $query = CommissionLog::query()
            ->where('invite_user_id', $userId)
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

    /**
     * 使用邀请码（注册后补填）。
     */
    public function useCode(int $userId, string $inviteCode): array
    {
        return DB::transaction(function () use ($userId, $inviteCode) {
            $user = User::query()->lockForUpdate()->find($userId);
            if (!$user) {
                return [
                    'ok' => false,
                    'error' => [404, 'User not found'],
                ];
            }

            if (!empty($user->invite_user_id)) {
                return [
                    'ok' => false,
                    'error' => [422, 'Invite user already bound'],
                ];
            }

            $inviteCodeModel = InviteCode::query()
                ->where('code', $inviteCode)
                ->where('status', InviteCode::STATUS_UNUSED)
                ->lockForUpdate()
                ->first();

            if (!$inviteCodeModel) {
                return [
                    'ok' => false,
                    'error' => [422, 'Invalid invitation code'],
                ];
            }

            if ((int) $inviteCodeModel->user_id === $userId) {
                return [
                    'ok' => false,
                    'error' => [422, 'Cannot use your own invitation code'],
                ];
            }

            $user->invite_user_id = (int) $inviteCodeModel->user_id;
            $user->save();

            if (!(int) admin_setting('invite_never_expire', 0)) {
                $inviteCodeModel->status = InviteCode::STATUS_USED;
                $inviteCodeModel->save();
            }

            return [
                'ok' => true,
                'data' => [
                    'bound' => true,
                    'inviterUserId' => (int) $user->invite_user_id,
                ],
            ];
        });
    }
}
