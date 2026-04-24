<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncJobTrigger;
use App\Http\Requests\Admin\SyncLogFetch;
use App\Http\Requests\Admin\SyncStateFetch;
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
    public function states(SyncStateFetch $request)
    {
        try {
            $params = $request->validated();

            $query = AdSyncState::with('account:id,account_name,source_platform');

            if (!empty($params['scope'])) {
                $query->where('sync_scope', $params['scope']);
            }
            if (!empty($params['accountId'])) {
                $query->where('account_id', $params['accountId']);
            }

            $pageSize = $params['pageSize'] ?? 20;
            $data = $query->orderByDesc('updated_at')->paginate($pageSize);

            return $this->ok([
                'data'     => $data->items(),
                'total'    => $data->total(),
                'page'     => $data->currentPage(),
                'pageSize' => $data->perPage(),
            ]);
        } catch (\Exception $e) {
            Log::error('SyncState fetch error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 查询同步日志
     * GET /admin/sync-logs
     */
    public function logs(SyncLogFetch $request)
    {
        try {
            $params = $request->validated();

            $query = AdSyncLog::with('account:id,account_name,source_platform');

            if (!empty($params['serverId'])) {
                $query->where('server_id', $params['serverId']);
            }
            if (!empty($params['status'])) {
                $query->where('status', $params['status']);
            }
            if (!empty($params['scope'])) {
                $query->where('sync_scope', $params['scope']);
            }
            if (!empty($params['startedFrom'])) {
                $query->where('started_at', '>=', $params['startedFrom']);
            }
            if (!empty($params['startedTo'])) {
                $query->where('started_at', '<=', $params['startedTo']);
            }

            $pageSize = $params['pageSize'] ?? 20;
            $data = $query->orderByDesc('started_at')->paginate($pageSize);

            return $this->ok([
                'data'     => $data->items(),
                'total'    => $data->total(),
                'page'     => $data->currentPage(),
                'pageSize' => $data->perPage(),
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

            return $this->ok(['message' => '同步任务已提交']);
        } catch (\Exception $e) {
            Log::error('SyncJob trigger error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
