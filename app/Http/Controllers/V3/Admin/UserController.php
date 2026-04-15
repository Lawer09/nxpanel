<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\V2\Admin\UserController as V2UserController;
use App\Http\Requests\Admin\UserUpdate;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\NodeSyncService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends V2UserController
{
    /**
     * 用户列表（分页）
     * 覆盖 V2：统一分页格式 {data, total, page, pageSize}
     */
    public function fetch(Request $request): JsonResponse
    {
        $current  = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 10);

        $userModel = User::with(['plan:id,name', 'invite_user:id,email', 'group:id,name'])
            ->select(DB::raw('*, (u+d) as total_used'));

        $this->applyFiltersAndSortsPublic($request, $userModel);

        $users = $userModel->orderBy('id', 'desc')
            ->paginate($pageSize, ['*'], 'page', $current);

        $users->getCollection()->transform(function ($user): array {
            return V2UserController::transformUserData($user);
        });

        return $this->ok([
            'data'     => $users->items(),
            'total'    => $users->total(),
            'page'     => $users->currentPage(),
            'pageSize' => $users->perPage(),
        ]);
    }

    /**
     * 用户详情
     * 覆盖 V2：使用 ok/error 格式
     */
    public function getUserInfoById(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '用户ID不能为空'
        ]);
        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->error([400202, '用户不存在']);
        }
        return $this->ok($user->load('invite_user'));
    }

    /**
     * 更新用户信息
     * 覆盖 V2：使用 ok/error 格式
     */
    public function update(UserUpdate $request): JsonResponse
    {
        $params = $request->validated();
        $user   = User::find($request->input('id'));
        if (!$user) {
            return $this->error([400202, '用户不存在']);
        }
        if (isset($params['email'])) {
            if (User::where('email', $params['email'])->first() && $user->email !== $params['email']) {
                return $this->error([400201, '邮箱已被使用']);
            }
        }
        if (isset($params['password'])) {
            $params['password']      = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = null;
        } else {
            unset($params['password']);
        }
        if (isset($params['plan_id'])) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                return $this->error([400202, '订阅计划不存在']);
            }
            $params['group_id'] = $plan->group_id;
        }
        if ($request->input('invite_user_email') && $inviteUser = User::where('email', $request->input('invite_user_email'))->first()) {
            $params['invite_user_id'] = $inviteUser->id;
        } else {
            $params['invite_user_id'] = null;
        }

        if (isset($params['password'])) {  
            $authService = new AuthService($user);  
            $authService->removeAllSessions();  
        }  

        if (isset($params['banned']) && (int) $params['banned'] === 1) {
            $authService = new AuthService($user);
            $authService->removeAllSessions();
        }
        if (isset($params['balance'])) {
            $params['balance'] = $params['balance'] * 100;
        }
        if (isset($params['commission_balance'])) {
            $params['commission_balance'] = $params['commission_balance'] * 100;
        }
        try {
            $user->update($params);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->error([500, '保存失败']);
        }
        return $this->ok(true);
    }

    /**
     * 重置订阅密钥
     * 覆盖 V2：使用 ok/error 格式
     */
    public function resetSecret(Request $request): JsonResponse
    {
        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->error([400202, '用户不存在']);
        }
        $user->token = Helper::guid();
        $user->uuid  = Helper::guid(true);
        return $this->ok($user->save());
    }

    /**
     * 封禁用户
     * 覆盖 V2：使用 ok/error 格式
     */
    public function ban(Request $request): JsonResponse
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort     = $request->input('sort') ?: 'created_at';
        $builder  = User::orderBy($sort, $sortType);
        $this->applyFiltersPublic($request, $builder);
        try {
            $builder->update(['banned' => 1]);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->error([500, '处理失败']);
        }
        NodeSyncService::notifyUsersUpdated();
        return $this->ok(true);
    }

    /**
     * 删除用户
     * 覆盖 V2：使用 ok/error 格式
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|exists:App\Models\User,id'
        ], [
            'id.required' => '用户ID不能为空',
            'id.exists'   => '用户不存在'
        ]);
        $user = User::find($request->input('id'));
        try {
            DB::beginTransaction();
            $user->orders()->delete();
            $user->codes()->delete();
            $user->stat()->delete();
            $user->tickets()->delete();
            $user->delete();
            DB::commit();
            return $this->ok(true);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->error([500, '删除失败']);
        }
    }

    // ----------------------------------------------------------------
    // Helpers: expose private methods from V2 via reflection
    // ----------------------------------------------------------------

    protected function applyFiltersAndSortsPublic(Request $request, Builder $builder): void
    {
        $ref = new \ReflectionMethod(V2UserController::class, 'applyFiltersAndSorts');
        $ref->setAccessible(true);
        $ref->invoke($this, $request, $builder);
    }

    protected function applyFiltersPublic(Request $request, Builder $builder): void
    {
        $ref = new \ReflectionMethod(V2UserController::class, 'applyFilters');
        $ref->setAccessible(true);
        $ref->invoke($this, $request, $builder);
    }
}
