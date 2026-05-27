<?php

namespace App\Http\Controllers\V3\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\InviteCodeCreateRequest;
use App\Http\Requests\User\InviteCodeUseRequest;
use App\Http\Requests\User\InviteCommissionListRequest;
use App\Http\Requests\User\InviteSummaryRequest;
use App\Http\Resources\ComissionLogResource;
use App\Http\Resources\InviteCodeResource;
use App\Services\InviteService;
use Illuminate\Http\JsonResponse;

class InviteController extends Controller
{
    public function __construct(
        protected InviteService $inviteService
    ) {}

    /**
     * 生成邀请码
     */
    public function createCode(InviteCodeCreateRequest $request): JsonResponse
    {
        $result = $this->inviteService->createCode((int) $request->user()->id);
        if (!$result['ok']) {
            return $this->error($result['error']);
        }

        return $this->ok($result['data']);
    }

    /**
     * 使用邀请码（注册后补填）。
     */
    public function useCode(InviteCodeUseRequest $request): JsonResponse
    {
        $params = $request->validated();
        $result = $this->inviteService->useCode((int) $request->user()->id, (string) $params['inviteCode']);

        if (!$result['ok']) {
            return $this->error($result['error']);
        }

        return $this->ok($result['data']);
    }


    /**
     * 邀请统计
     */
    public function summary(InviteSummaryRequest $request): JsonResponse
    {
        $result = $this->inviteService->summary((int) $request->user()->id);
        if (!$result) {
            return $this->error([404, 'User not found']);
        }

        return $this->ok($result);
    }

    /**
     * 返佣明细分页
     */
    public function commissions(InviteCommissionListRequest $request): JsonResponse
    {
        $params = $request->validated();
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 10);
        $result = $this->inviteService->commissions((int) $request->user()->id, $page, $pageSize);

        return $this->ok([
            'data' => ComissionLogResource::collection($result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'pageSize' => $result['pageSize'],
        ]);
    }
}
