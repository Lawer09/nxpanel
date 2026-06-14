<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\V2\Admin\UserController as V2UserController;
use App\Http\Requests\Admin\UserBatchBanRequest;
use App\Http\Requests\Admin\UserUpdate;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\BlockedUserIpService;
use App\Services\NodeSyncService;
use App\Http\Resources\CamelizeResource;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends V2UserController
{

    public function fetch(Request $request): JsonResponse
    {
        $userModel = User::with(['plan:id,name', 'invite_user:id,email', 'group:id,name'])
            ->select(DB::raw('*, (u+d) as total_used'));

        $metadataFilters = $request->input('meta') ?? $request->input('register_metadata');
        if (is_array($metadataFilters)) {
            foreach ($metadataFilters as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $userModel->where("register_metadata->{$key}", $value);
                }
            }
        }

        if ($request->filled('id')) {
            $ids = is_array($id = $request->input('id')) ? $id : explode(',', $id);
            $userModel->whereIn('id', $ids);
        }

        if ($request->boolean('onlyBanned')) {
            $userModel->where('banned', 1);
        }

        $current  = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 10);

        $this->applyFiltersAndSortsPublic($request, $userModel);

        $users = $userModel->orderBy('id', 'desc')
            ->paginate($pageSize, ['*'], 'page', $current);

        $userIds = collect($users->items())->pluck('id')->filter()->values();
        $reportTrafficMap = [];

        if ($userIds->isNotEmpty()) {
            $reportTrafficMap = DB::table('v2_node_traffic_aggregated')
                ->whereDate('date', now()->toDateString())
                ->whereIn('user_id', $userIds->all())
                ->selectRaw('user_id, ROUND(SUM(total_usage_mb), 3) as report_traffic')
                ->groupBy('user_id')
                ->get()
                ->mapWithKeys(fn($row) => [(int) $row->user_id => (float) ($row->report_traffic ?? 0)])
                ->toArray();
        }

        $users->getCollection()->transform(function ($user) use ($reportTrafficMap): array {
            $data = V2UserController::transformUserData($user);
            $data['report_traffic'] = $reportTrafficMap[(int) ($user->id ?? 0)] ?? 0.0;
            return $data;
        });

        return $this->ok([
            'data'     => $users->items(),
            'total'    => $users->total(),
            'page'     => $users->currentPage(),
            'pageSize' => $users->perPage(),
        ]);
    }

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
        if (isset($params['register_metadata'])) {
            if (is_string($params['register_metadata'])) {
                $params['register_metadata'] = json_decode($params['register_metadata'], true);
            }
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
     * Batch ban users and persist their registration IPs in the block list.
     */
    public function batchBan(UserBatchBanRequest $request, BlockedUserIpService $blockedUserIpService): JsonResponse
    {
        $userIds = collect($request->validated('user_ids'))
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values();
        $reason = $request->validated('reason') ?? null;
        $operatorUserId = Auth::guard('sanctum')->id();

        try {
            $result = DB::transaction(function () use ($userIds, $reason, $operatorUserId, $blockedUserIpService) {
                $users = User::query()
                    ->whereIn('id', $userIds->all())
                    ->lockForUpdate()
                    ->get();

                foreach ($users as $user) {
                    $user->banned = 1;
                    $user->save();
                    (new AuthService($user))->removeAllSessions();
                }

                $ipResult = $blockedUserIpService->blockIpsForUsers($users, $operatorUserId, $reason);

                return [
                    'bannedUserCount' => $users->count(),
                    'blockedIpCount' => count($ipResult['blocked_ips']),
                    'blockedIps' => $ipResult['blocked_ips'],
                    'skippedIpUserIds' => $ipResult['skipped_user_ids'],
                ];
            });
        } catch (\Exception $e) {
            Log::error('Batch ban users failed', ['error' => $e->getMessage()]);
            return $this->error([500, 'Batch ban failed']);
        }

        NodeSyncService::notifyUsersUpdated();

        return $this->ok($result);
    }

    /**
     * 删除用户
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

    /**
     * 用户报表（按天）
     *
     * GET /user/report
     */
    public function report(Request $request): JsonResponse
    {
        $request->validate([
            'userId'        => 'required|integer|exists:v2_user,id',
            'dateFrom'      => 'nullable|date',
            'dateTo'        => 'nullable|date',
            'clientCountry' => 'nullable|string|max:2',
            'platform'      => 'nullable|string|max:100',
            'appId'         => 'nullable|string|max:255',
            'appVersion'    => 'nullable|string|max:50',
            'pageSize'      => 'nullable|integer|min:1|max:200',
        ]);

        $userId = (int) $request->input('userId');
        $dateFrom = $request->input('dateFrom', now()->subDays(29)->toDateString());
        $dateTo = $request->input('dateTo', now()->toDateString());
        $pageSize = (int) $request->input('pageSize', 30);

        $dailyQuery = DB::table('v3_user_report_count as urc')
            ->where('urc.user_id', $userId)
            ->where('urc.date', '>=', $dateFrom)
            ->where('urc.date', '<=', $dateTo);

        if ($request->filled('clientCountry')) {
            $dailyQuery->where('urc.client_country', $request->input('clientCountry'));
        }
        if ($request->filled('platform')) {
            $dailyQuery->where('urc.platform', $request->input('platform'));
        }
        if ($request->filled('appId')) {
            $dailyQuery->where('urc.app_id', $request->input('appId'));
        }
        if ($request->filled('appVersion')) {
            $dailyQuery->where('urc.app_version', $request->input('appVersion'));
        }

        $data = $dailyQuery
            ->selectRaw('urc.date, urc.user_id')
            ->selectRaw('SUM(urc.report_count) as total_reports')
            ->selectRaw('MAX(urc.node_count) as max_nodes')
            ->selectRaw('MAX(urc.client_country) as client_country')
            ->selectRaw('MAX(urc.client_isp) as client_isp')
            ->selectRaw('MAX(urc.platform) as platform')
            ->selectRaw('MAX(urc.app_id) as app_id')
            ->selectRaw('MAX(urc.app_version) as app_version')
            ->groupBy('urc.date', 'urc.user_id')
            ->orderByDesc('urc.date')
            ->paginate($pageSize);

        $rows = collect($data->items());
        $dateList = $rows->pluck('date')->filter()->unique()->values();

        $trafficMap = [];
        $probeMap = [];

        if ($dateList->isNotEmpty()) {
            $trafficQuery = DB::table('v2_node_traffic_aggregated as t')
                ->where('t.user_id', $userId)
                ->whereIn('t.date', $dateList->all());

            if ($request->filled('clientCountry')) {
                $trafficQuery->where('t.client_country', $request->input('clientCountry'));
            }
            if ($request->filled('platform')) {
                $trafficQuery->where('t.platform', $request->input('platform'));
            }
            if ($request->filled('appId')) {
                $trafficQuery->where('t.app_id', $request->input('appId'));
            }
            if ($request->filled('appVersion')) {
                $trafficQuery->where('t.app_version', $request->input('appVersion'));
            }

            $trafficMap = $trafficQuery
                ->selectRaw('t.date')
                ->selectRaw('SUM(t.total_usage_seconds) as total_usage_seconds')
                ->selectRaw('ROUND(SUM(t.total_usage_mb), 3) as total_usage_mb')
                ->selectRaw('SUM(t.report_count) as traffic_report_count')
                ->groupBy('t.date')
                ->get()
                ->mapWithKeys(fn($row) => [(string) $row->date => [
                    'total_usage_seconds' => (int) ($row->total_usage_seconds ?? 0),
                    'total_usage_mb' => (float) ($row->total_usage_mb ?? 0),
                    'traffic_report_count' => (int) ($row->traffic_report_count ?? 0),
                ]])
                ->toArray();

            $probeQuery = DB::table('v2_node_probe_aggregated as p')
                ->whereIn('p.date', $dateList->all());

            if ($request->filled('clientCountry')) {
                $probeQuery->where('p.client_country', $request->input('clientCountry'));
            }
            if ($request->filled('platform')) {
                $probeQuery->where('p.platform', $request->input('platform'));
            }
            if ($request->filled('appId')) {
                $probeQuery->where('p.app_id', $request->input('appId'));
            }
            if ($request->filled('appVersion')) {
                $probeQuery->where('p.app_version', $request->input('appVersion'));
            }

            $probeMap = $probeQuery
                ->selectRaw('p.date')
                ->selectRaw('SUM(p.total_count) as probe_total_count')
                ->selectRaw("SUM(CASE WHEN p.status = 'success' THEN p.total_count ELSE 0 END) as probe_success_count")
                ->selectRaw("SUM(CASE WHEN p.status IN ('failed', 'timeout', 'cancelled') THEN p.total_count ELSE 0 END) as probe_failed_count")
                ->groupBy('p.date')
                ->get()
                ->mapWithKeys(fn($row) => [(string) $row->date => [
                    'probe_total_count' => (int) ($row->probe_total_count ?? 0),
                    'probe_success_count' => (int) ($row->probe_success_count ?? 0),
                    'probe_failed_count' => (int) ($row->probe_failed_count ?? 0),
                ]])
                ->toArray();
        }

        $mapped = $rows->map(function ($row) use ($trafficMap, $probeMap) {
            $date = (string) $row->date;
            $traffic = $trafficMap[$date] ?? [
                'total_usage_seconds' => 0,
                'total_usage_mb' => 0,
                'traffic_report_count' => 0,
            ];
            $probe = $probeMap[$date] ?? [
                'probe_total_count' => 0,
                'probe_success_count' => 0,
                'probe_failed_count' => 0,
            ];

            return array_merge((array) $row, $traffic, $probe);
        })->values();

        return $this->ok([
            'data' => CamelizeResource::collection($mapped),
            'total' => $data->total(),
            'page' => $data->currentPage(),
            'pageSize' => $data->perPage(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
        ]);
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
