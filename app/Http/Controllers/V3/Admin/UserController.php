<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\V2\Admin\UserController as V2UserController;
use App\Http\Requests\Admin\AidLoginBanRuleDeleteRequest;
use App\Http\Requests\Admin\AidLoginBanRuleFetchRequest;
use App\Http\Requests\Admin\AidLoginBanRuleSaveRequest;
use App\Http\Requests\Admin\AidLoginBanRuleUpdateRequest;
use App\Http\Requests\Admin\AllowedUserIpBatchDeleteRequest;
use App\Http\Requests\Admin\AllowedUserIpDeleteRequest;
use App\Http\Requests\Admin\AllowedUserIpFetchRequest;
use App\Http\Requests\Admin\AllowedUserIpSaveRequest;
use App\Http\Requests\Admin\BlockedUserIpBatchBlockRequest;
use App\Http\Requests\Admin\BlockedUserIpBatchDeleteRequest;
use App\Http\Requests\Admin\BlockedUserIpDeleteRequest;
use App\Http\Requests\Admin\BlockedUserIpFetchRequest;
use App\Http\Requests\Admin\BlockedUserIpUpdateTypeRequest;
use App\Http\Requests\Admin\IpAllowlistRuleDeleteRequest;
use App\Http\Requests\Admin\IpAllowlistRuleFetchRequest;
use App\Http\Requests\Admin\IpAllowlistRuleSaveRequest;
use App\Http\Requests\Admin\IpAllowlistRuleUpdateRequest;
use App\Http\Requests\Admin\UserBatchBanRequest;
use App\Models\AllowedUserIp;
use App\Models\AidLoginBanRule;
use App\Http\Requests\Admin\UserUpdate;
use App\Models\BlockedUserIp;
use App\Models\IpAllowlistRule;
use App\Models\Plan;
use App\Models\User;
use App\Services\AidLoginBanRuleService;
use App\Services\AllowedUserIpService;
use App\Services\AuthService;
use App\Services\BlockedUserIpService;
use App\Services\IpAllowlistRuleService;
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

        $this->applyRegisterMetadataFilters($request, $userModel);

        if ($request->filled('id')) {
            $ids = is_array($id = $request->input('id')) ? $id : explode(',', $id);
            $userModel->whereIn('id', $ids);
        }

        if ($request->boolean('onlyBanned')) {
            $userModel->where('banned', 1);
        }

        $this->applyCreatedAtRange($request, $userModel);

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
        $type = $request->validated('type') ?? BlockedUserIp::TYPE_NORMAL;

        try {
            $users = User::query()->whereIn('id', $userIds->all())->get();
            $result = $blockedUserIpService->banUsersAndBlockIps($users, $operatorUserId, $reason, [
                'source' => 'admin_batch_ban',
            ], $type);
        } catch (\Exception $e) {
            Log::error('Batch ban users failed', ['error' => $e->getMessage()]);
            return $this->error([500, 'Batch ban failed']);
        }

        NodeSyncService::notifyUsersUpdated();

        return $this->ok($result);
    }

    /**
     * Query custom AID login ban rules.
     */
    public function fetchAidLoginBanRules(
        AidLoginBanRuleFetchRequest $request,
        AidLoginBanRuleService $aidLoginBanRuleService
    ): JsonResponse {
        $result = $aidLoginBanRuleService->paginate($request->validated());

        return $this->ok([
            'data' => collect($result->items())
                ->map(fn(AidLoginBanRule $rule): array => $aidLoginBanRuleService->transform($rule))
                ->values(),
            'total' => $result->total(),
            'page' => $result->currentPage(),
            'pageSize' => $result->perPage(),
        ]);
    }

    /**
     * Create a custom AID login ban rule.
     */
    public function saveAidLoginBanRule(
        AidLoginBanRuleSaveRequest $request,
        AidLoginBanRuleService $aidLoginBanRuleService
    ): JsonResponse {
        $rule = $aidLoginBanRuleService->create(
            $request->validated(),
            Auth::guard('sanctum')->id()
        );

        return $this->ok($aidLoginBanRuleService->transform($rule));
    }

    /**
     * Update a custom AID login ban rule.
     */
    public function updateAidLoginBanRule(
        AidLoginBanRuleUpdateRequest $request,
        AidLoginBanRuleService $aidLoginBanRuleService
    ): JsonResponse {
        $data = $request->validated();
        $rule = $aidLoginBanRuleService->update(
            (int) $data['id'],
            $data,
            Auth::guard('sanctum')->id()
        );

        return $this->ok($aidLoginBanRuleService->transform($rule));
    }

    /**
     * Delete a custom AID login ban rule.
     */
    public function deleteAidLoginBanRule(
        AidLoginBanRuleDeleteRequest $request,
        AidLoginBanRuleService $aidLoginBanRuleService
    ): JsonResponse {
        $deleted = $aidLoginBanRuleService->delete((int) $request->validated('id'));
        if (!$deleted) {
            return $this->error([400202, 'AID login ban rule not found']);
        }

        return $this->ok(true);
    }

    /**
     * 删除用户
     */
    /**
     * Query blocked registration IP records for admin management.
     */
    public function fetchBlockedIps(BlockedUserIpFetchRequest $request, BlockedUserIpService $blockedUserIpService): JsonResponse
    {
        $result = $blockedUserIpService->paginate($request->validated());

        return $this->ok([
            'data' => collect($result->items())->map(function (BlockedUserIp $record): array {
                return [
                    'id' => (int) $record->id,
                    'ip' => (string) $record->ip,
                    'type' => $record->type ?: BlockedUserIp::TYPE_NORMAL,
                    'reason' => $record->reason,
                    'metadata' => $record->metadata,
                    'banned_user_id' => $record->banned_user_id,
                    'operator_user_id' => $record->operator_user_id,
                    'banned_user' => $record->bannedUser ? [
                        'id' => (int) $record->bannedUser->id,
                        'email' => $record->bannedUser->email,
                    ] : null,
                    'operator_user' => $record->operatorUser ? [
                        'id' => (int) $record->operatorUser->id,
                        'email' => $record->operatorUser->email,
                    ] : null,
                    'created_at' => $record->created_at,
                    'updated_at' => $record->updated_at,
                ];
            })->values(),
            'total' => $result->total(),
            'page' => $result->currentPage(),
            'pageSize' => $result->perPage(),
        ]);
    }

    /**
     * Delete a blocked registration IP record by id.
     */
    public function deleteBlockedIp(BlockedUserIpDeleteRequest $request, BlockedUserIpService $blockedUserIpService): JsonResponse
    {
        $deleted = $blockedUserIpService->deleteById((int) $request->validated('id'));
        if (!$deleted) {
            return $this->error([400202, '封禁IP记录不存在']);
        }

        return $this->ok(true);
    }

    /**
     * Batch delete blocked registration IP records by ids.
     */
    public function batchDeleteBlockedIps(
        BlockedUserIpBatchDeleteRequest $request,
        BlockedUserIpService $blockedUserIpService
    ): JsonResponse {
        return $this->ok(
            $blockedUserIpService->batchDeleteByIds($request->validated('ids'))
        );
    }

    /**
     * Batch block explicit IP addresses and optionally ban matched users.
     */
    public function batchBlockIps(
        BlockedUserIpBatchBlockRequest $request,
        BlockedUserIpService $blockedUserIpService
    ): JsonResponse {
        $params = $request->validated();
        $result = $blockedUserIpService->batchBlockIps(
            $params['ips'],
            Auth::guard('sanctum')->id(),
            $params['reason'] ?? null,
            $params['type'] ?? BlockedUserIp::TYPE_NORMAL,
            (bool) ($params['banUsers'] ?? false),
            ['source' => 'admin_batch_block_ip']
        );

        if (($result['bannedUserCount'] ?? 0) > 0) {
            NodeSyncService::notifyUsersUpdated();
        }

        return $this->ok($result);
    }

    /**
     * Update a blocked registration IP record type.
     */
    public function updateBlockedIpType(
        BlockedUserIpUpdateTypeRequest $request,
        BlockedUserIpService $blockedUserIpService
    ): JsonResponse {
        $record = $blockedUserIpService->updateTypeById(
            (int) $request->validated('id'),
            (string) $request->validated('type')
        );

        if (!$record) {
            return $this->error([400202, 'Blocked IP record not found']);
        }

        return $this->ok([
            'id' => (int) $record->id,
            'ip' => (string) $record->ip,
            'type' => $record->type ?: BlockedUserIp::TYPE_NORMAL,
        ]);
    }

    /**
     * Query IP allowlist records for admin management.
     */
    public function fetchAllowedIps(
        AllowedUserIpFetchRequest $request,
        AllowedUserIpService $allowedUserIpService
    ): JsonResponse {
        $result = $allowedUserIpService->paginate($request->validated());

        return $this->ok([
            'data' => collect($result->items())
                ->map(fn(AllowedUserIp $record): array => $allowedUserIpService->transform($record))
                ->values(),
            'total' => $result->total(),
            'page' => $result->currentPage(),
            'pageSize' => $result->perPage(),
        ]);
    }

    /**
     * Add or update IP allowlist records.
     */
    public function saveAllowedIps(
        AllowedUserIpSaveRequest $request,
        AllowedUserIpService $allowedUserIpService
    ): JsonResponse {
        return $this->ok($allowedUserIpService->saveIps(
            $request->validated('ips'),
            Auth::guard('sanctum')->id(),
            $request->validated('reason') ?? null,
            ['source' => 'admin_allowed_ip_save']
        ));
    }

    /**
     * Delete one IP allowlist record.
     */
    public function deleteAllowedIp(
        AllowedUserIpDeleteRequest $request,
        AllowedUserIpService $allowedUserIpService
    ): JsonResponse {
        $deleted = $allowedUserIpService->deleteById((int) $request->validated('id'));
        if (!$deleted) {
            return $this->error([400202, 'Allowed IP record not found']);
        }

        return $this->ok(true);
    }

    /**
     * Batch delete IP allowlist records.
     */
    public function batchDeleteAllowedIps(
        AllowedUserIpBatchDeleteRequest $request,
        AllowedUserIpService $allowedUserIpService
    ): JsonResponse {
        return $this->ok($allowedUserIpService->batchDeleteByIds($request->validated('ids')));
    }

    /**
     * Query IP allowlist rules.
     */
    public function fetchIpAllowlistRules(
        IpAllowlistRuleFetchRequest $request,
        IpAllowlistRuleService $ipAllowlistRuleService
    ): JsonResponse {
        $result = $ipAllowlistRuleService->paginate($request->validated());

        return $this->ok([
            'data' => collect($result->items())
                ->map(fn(IpAllowlistRule $rule): array => $ipAllowlistRuleService->transform($rule))
                ->values(),
            'total' => $result->total(),
            'page' => $result->currentPage(),
            'pageSize' => $result->perPage(),
        ]);
    }

    /**
     * Create an IP allowlist rule.
     */
    public function saveIpAllowlistRule(
        IpAllowlistRuleSaveRequest $request,
        IpAllowlistRuleService $ipAllowlistRuleService
    ): JsonResponse {
        try {
            $rule = $ipAllowlistRuleService->create(
                $request->validated(),
                Auth::guard('sanctum')->id()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error([422, $e->getMessage()]);
        }

        return $this->ok($ipAllowlistRuleService->transform($rule));
    }

    /**
     * Update an IP allowlist rule.
     */
    public function updateIpAllowlistRule(
        IpAllowlistRuleUpdateRequest $request,
        IpAllowlistRuleService $ipAllowlistRuleService
    ): JsonResponse {
        $data = $request->validated();
        try {
            $rule = $ipAllowlistRuleService->update(
                (int) $data['id'],
                $data,
                Auth::guard('sanctum')->id()
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error([422, $e->getMessage()]);
        }

        return $this->ok($ipAllowlistRuleService->transform($rule));
    }

    /**
     * Delete an IP allowlist rule.
     */
    public function deleteIpAllowlistRule(
        IpAllowlistRuleDeleteRequest $request,
        IpAllowlistRuleService $ipAllowlistRuleService
    ): JsonResponse {
        $deleted = $ipAllowlistRuleService->delete((int) $request->validated('id'));
        if (!$deleted) {
            return $this->error([400202, 'IP allowlist rule not found']);
        }

        return $this->ok(true);
    }

    /**
     * Delete a user and related records.
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

    /**
     * Apply registration timestamp range filters to the admin user list.
     */
    protected function applyCreatedAtRange(Request $request, Builder $builder): void
    {
        $from = $this->parseCreatedAtBoundary($request->input('createdAtFrom'), false);
        $to = $this->parseCreatedAtBoundary($request->input('createdAtTo'), true);

        if ($from !== null) {
            $builder->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $builder->where('created_at', '<=', $to);
        }
    }

    /**
     * Convert a Unix timestamp or date string into a user.created_at boundary.
     */
    protected function parseCreatedAtBoundary(mixed $value, bool $endOfDay): ?int
    {
        if (is_array($value) || $value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $value .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    protected function applyFiltersAndSortsPublic(Request $request, Builder $builder): void
    {
        if ($request->has('filter')) {
            $filters = collect((array) $request->input('filter'))
                ->reject(fn($filter) => $this->isRegisterMetadataFilter($filter))
                ->values()
                ->all();

            $request->merge(['filter' => $filters]);
        }

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

    /**
     * Apply exact filters for user registration metadata.
     */
    protected function applyRegisterMetadataFilters(Request $request, Builder $builder): void
    {
        $metadataFilters = $request->input('meta') ?? $request->input('register_metadata');
        $metadataFilters = is_array($metadataFilters) ? $metadataFilters : [];

        foreach (['app_id', 'country', 'ip'] as $key) {
            if ($request->filled($key) && !array_key_exists($key, $metadataFilters)) {
                $metadataFilters[$key] = $request->input($key);
            }
        }

        foreach ((array) $request->input('filter', []) as $filter) {
            if (!is_array($filter) || !isset($filter['id']) || !array_key_exists('value', $filter)) {
                continue;
            }

            $key = (string) $filter['id'];
            if (in_array($key, ['app_id', 'country', 'ip'], true) && !array_key_exists($key, $metadataFilters)) {
                $metadataFilters[$key] = $filter['value'];
            }
        }

        foreach ($metadataFilters as $key => $value) {
            if (!is_string($key) || (!is_string($value) && !is_numeric($value))) {
                continue;
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            if ($key === 'country') {
                $value = strtoupper($value);
            }

            $builder->where("register_metadata->{$key}", $value);
        }
    }

    /**
     * Determine whether a table filter targets registration metadata.
     */
    protected function isRegisterMetadataFilter(mixed $filter): bool
    {
        if (!is_array($filter) || !isset($filter['id'])) {
            return false;
        }

        return in_array((string) $filter['id'], ['app_id', 'country', 'ip'], true);
    }
}
