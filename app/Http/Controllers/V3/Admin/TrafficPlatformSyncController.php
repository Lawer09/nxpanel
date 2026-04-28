<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CamelizeResource;
use App\Models\TrafficPlatformAccount;
use App\Models\TrafficPlatformSyncJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrafficPlatformSyncController extends Controller
{
    /**
     * 手动触发同步
     * POST /traffic-platform/sync
     *
     * 转发给 Go 内部接口
     */
    public function trigger(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'accountId'    => 'required|integer',
                'platformCode' => 'required|string|max:50',
                'startDate'    => 'required|date',
                'endDate'      => 'required|date',
            ]);

            $account = TrafficPlatformAccount::find($request->input('accountId'));
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $goUrl = 'http://47.254.131.223:8080/api/traffic-platform/sync';

            $response = Http::timeout(15)->post($goUrl, [
                'account_id'    => $request->input('accountId'),
                'platform_code' => $request->input('platformCode'),
                'start_date'    => $request->input('startDate'),
                'end_date'      => $request->input('endDate'),
            ]);

            if ($response->successful()) {
                $body = $response->json();
                return $this->ok($body['data'] ?? $body);
            }

            return $this->error([502, '触发同步失败: ' . $response->body()]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformSync trigger error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 同步任务列表
     * GET /traffic-platform/sync-jobs
     */
    public function fetch(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'platformCode' => 'nullable|string|max:50',
                'accountId'    => 'nullable|integer',
                'status'       => 'nullable|string|in:running,success,failed',
                'startTime'    => 'nullable|date',
                'endTime'      => 'nullable|date',
                'page'         => 'nullable|integer|min:1',
                'pageSize'     => 'nullable|integer|min:1|max:200',
            ]);

            $query = TrafficPlatformSyncJob::query();

            if ($request->filled('platformCode')) {
                $query->where('platform_code', $request->input('platformCode'));
            }
            if ($request->filled('accountId')) {
                $query->where('platform_account_id', $request->input('accountId'));
            }
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }
            if ($request->filled('startTime')) {
                $query->where('created_at', '>=', $request->input('startTime'));
            }
            if ($request->filled('endTime')) {
                $query->where('created_at', '<=', $request->input('endTime'));
            }

            $page     = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 20);

            $total = $query->count();
            $items = $query->orderByDesc('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            // 补充 account_name
            $accountIds = $items->pluck('platform_account_id')->unique()->filter()->values();
            $accountMap = TrafficPlatformAccount::whereIn('id', $accountIds)->pluck('account_name', 'id');

            $list = $items->map(function ($item) use ($accountMap) {
                $arr = $item->toArray();
                $arr['account_name'] = $accountMap[$item->platform_account_id] ?? '';
                return $arr;
            });

            return $this->ok([
                'page'     => $page,
                'pageSize' => $pageSize,
                'total'    => $total,
                'data'     => CamelizeResource::collection($list),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformSync fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 同步任务详情
     * GET /traffic-platform/sync-jobs/{id}
     */
    public function detail(int $id): JsonResponse
    {
        try {
            $job = TrafficPlatformSyncJob::find($id);
            if (!$job) {
                return $this->error([404, '同步任务不存在']);
            }

            $arr = $job->toArray();
            $account = TrafficPlatformAccount::find($job->platform_account_id);
            $arr['account_name'] = $account?->account_name ?? '';

            return $this->ok(CamelizeResource::make($arr));
        } catch (\Exception $e) {
            Log::error('TrafficPlatformSync detail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
