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
        $userCodes = InviteCode::query()
            ->where('user_id', $userId)
            ->where('status', InviteCode::STATUS_UNUSED)
            ->get();

        // if ($unusedCount >= (int) admin_setting('invite_gen_limit', 5)) {
        if ($userCodes->isNotEmpty()) {
            return [
                'ok' => true,
                'data' => $userCodes->map(fn($code) => [
                    'code' => explode('-', $code->code)[1],
                    'status' => $code->status,
                    'created' => true,
                ])->values()->toArray(),
            ];
        }

        $inviteCode = new InviteCode();
        $inviteCode->user_id = $userId;
        $inviteCode->code = 'MU-' . Helper::randomChar(4);

        return [
            'ok' => true,
            'data' => [
                [
                    'created' => (bool) $inviteCode->save(),
                    'status' => $inviteCode->status,
                    'code' => explode('-', $inviteCode->code)[1],
                ]
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

        $invitedUsers = User::query()
            ->where('invite_user_id', $user->id)
            ->select(['id', 'email', 'created_at', 'register_metadata'])
            ->orderByDesc('id')
            ->get()
            ->map(fn(User $invitedUser) => [
                'userId' => (int) $invitedUser->id,
                'userIdentifier' => (string) $invitedUser->email,
                'usedAt' => $this->getInviteUsedAt($invitedUser),
            ])
            ->values()
            ->toArray();

        return [
            // 'codes' => $user->codes,
            'invitedUsers' => count($invitedUsers),
            'users' => $invitedUsers,
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
        $inviteCode = 'MU-' . $inviteCode;
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
            $metadata = is_array($user->register_metadata) ? $user->register_metadata : [];
            $metadata['invite_code_used_at'] = time();
            $user->register_metadata = $metadata;
            $user->save();

            $isMultiUseCode = str_starts_with((string) $inviteCodeModel->code, 'MU-');
            if (!(int) admin_setting('invite_never_expire', 0) && !$isMultiUseCode) {
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

    /**
     * Get the time when the invited user used an invitation.
     */
    private function getInviteUsedAt(User $user): ?int
    {
        $metadata = is_array($user->register_metadata) ? $user->register_metadata : [];
        $usedAt = $metadata['invite_code_used_at'] ?? null;

        if (is_numeric($usedAt)) {
            return (int) $usedAt;
        }

        return $user->created_at ? (int) $user->created_at : null;
    }
}
