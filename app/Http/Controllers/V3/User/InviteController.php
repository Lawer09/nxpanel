<?php

namespace App\Http\Controllers\V3\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\InviteCommissionListRequest;
use App\Http\Resources\ComissionLogResource;
use App\Http\Resources\InviteCodeResource;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    /**
     * 生成邀请码
     */
    public function createCode(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $unusedCount = InviteCode::query()
            ->where('user_id', $userId)
            ->where('status', InviteCode::STATUS_UNUSED)
            ->count();

        if ($unusedCount >= (int) admin_setting('invite_gen_limit', 5)) {
            return $this->error([400, __('The maximum number of creations has been reached')]);
        }

        $inviteCode = new InviteCode();
        $inviteCode->user_id = $userId;
        $inviteCode->code = Helper::randomChar(8);

        return $this->ok([
            'created' => (bool) $inviteCode->save(),
            'code' => $inviteCode->code,
        ]);
    }

    /**
     * 邀请统计
     */
    public function summary(Request $request): JsonResponse
    {
        $user = User::query()
            ->find($request->user()->id)
            ?->load(['codes' => fn($query) => $query->where('status', InviteCode::STATUS_UNUSED)]);

        if (!$user) {
            return $this->error([404, 'User not found']);
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

        return $this->ok([
            'codes' => InviteCodeResource::collection($user->codes),
            'summary' => [
                'invitedUsers' => (int) User::query()->where('invite_user_id', $user->id)->count(),
                'totalCommission' => (int) CommissionLog::query()->where('invite_user_id', $user->id)->sum('get_amount'),
                'pendingCommission' => $pendingCommission,
                'commissionRate' => $commissionRate,
                'availableCommission' => (int) $user->commission_balance,
            ],
        ]);
    }

    /**
     * 返佣明细分页
     */
    public function commissions(InviteCommissionListRequest $request): JsonResponse
    {
        $params = $request->validated();
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);

        $query = CommissionLog::query()
            ->where('invite_user_id', $request->user()->id)
            ->where('get_amount', '>', 0)
            ->orderByDesc('created_at');

        $total = $query->count();
        $items = $query->forPage($page, $pageSize)->get();

        return $this->ok([
            'data' => ComissionLogResource::collection($items),
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
        ]);
    }
}
