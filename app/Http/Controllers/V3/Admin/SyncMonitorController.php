<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncJobTrigger;
use App\Models\AdSyncState;
use App\Models\AdSyncLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SyncMonitorController extends Controller
{
    /**
     * 查询同步状态
     * GET /admin/sync-states
     */
    public function states(Request $request)
    {
        try {
            $scope     = $request->query('scope');
            $accountId = $request->query('account_id');

            $query = AdSyncState::with('account:id,account_name,source_platform');

            if ($scope) {
                $query->where('sync_scope', $scope);
            }
            if ($accountId) {
                $query->where('account_id', $accountId);
            }

            $data = $query->orderByDesc('updated_at')->get();

            return $this->ok($data);
        } catch (\Exception $e) {
            Log::error('SyncState fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 查询同步日志
     * GET /admin/sync-logs
     */
    public function logs(Request $request)
    {
        try {
            $page       = (int) $request->query('page', 1);
            $size       = (int) $request->query('size', 20);
            $serverId   = $request->query('server_id');
            $status     = $request->query('status');
            $scope      = $request->query('scope');
            $startedFrom = $request->query('started_from');
            $startedTo   = $request->query('started_to');

            $query = AdSyncLog::with('account:id,account_name,source_platform');

            if ($serverId) {
                $query->where('server_id', $serverId);
            }
            if ($status) {
                $query->where('status', $status);
            }
            if ($scope) {
                $query->where('sync_scope', $scope);
            }
            if ($startedFrom) {
                $query->where('started_at', '>=', $startedFrom);
            }
            if ($startedTo) {
                $query->where('started_at', '<=', $startedTo);
            }

            $total = $query->count();
            $items = $query->orderByDesc('started_at')
                ->offset(($page - 1) * $size)
                ->limit($size)
                ->get();

            return $this->ok([
                'page'  => $page,
                'size'  => $size,
                'total' => $total,
                'items' => $items,
            ]);
        } catch (\Exception $e) {
            Log::error('SyncLog fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 手动触发同步任务
     * POST /admin/sync-jobs/trigger
     */
    public function trigger(SyncJobTrigger $request)
    {
        try {
            $params = $request->validated();

            // TODO: 将同步任务推入队列（根据 scope + account_ids / assigned_server_id）
            // 这里仅记录日志，实际需要 dispatch Job
            Log::info('Sync job triggered', $params);

            return $this->ok(['message' => '同步任务已提交'], [202, '已接受']);
        } catch (\Exception $e) {
            Log::error('SyncJob trigger error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
