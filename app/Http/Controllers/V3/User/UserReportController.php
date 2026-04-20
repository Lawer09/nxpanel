<?php

namespace App\Http\Controllers\V3\User;

use App\Http\Controllers\Controller;
use App\Models\NodePerformanceReport;
use App\Http\Requests\User\PerformanceBatchReport;
use App\Http\Requests\User\PerformanceHistory;
use App\Http\Requests\User\PerformanceNodeStats;
use App\Http\Requests\User\PerformanceReport;
use App\Services\NodePerformanceService;
use App\Services\IpInfoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserReportController extends Controller
{
    /**
     * 单个节点性能上报
     */
    public function report(PerformanceReport $request)
    {
        $validated = $request->validated();
        try {
            NodePerformanceService::reportPerformance(
                $request->user()->id,
                $validated['node_id'],
                $validated,
                $request->getClientIp(),
                $request
            );
            
            return $this->ok();
        } catch (\Exception $e) {
            Log::error('Performance report error', ['error' => $e->getMessage()]);
            return $this->error([500, '上报失败，请稍后重试']);
        }
    }

    /**
     * 批量节点性能上报
     */
    public function batchReport(PerformanceBatchReport $request)
    {
        $validated = $request->validated();
        try {
            NodePerformanceService::batchReportPerformance(
                $request->user()->id,
                $validated['reports'],
                $validated['metadata'] ?? [],
                $request->getClientIp(),
                $request
            );

            return $this->ok();
        } catch (\Exception $e) {
            Log::error('Batch performance report error', ['error' => $e->getMessage()]);
            return $this->error([500, '批量上报失败，请稍后重试']);
        }
    }

    /**
     * 获取用户的上报历史
     */
    public function getHistory(PerformanceHistory $request)
    {
        $validated = $request->validated();
        try {
            $userId = $request->user()->id;
            if (!$userId) {
                return $this->error([401, '未授权']);
            }
            
            $limit = $validated['limit'] ?? 100;

            $query = NodePerformanceReport::where('user_id', $userId);

            if ($request->filled('node_id')) {
                $query->where('node_id', $validated['node_id']);
            }

            $reports = $query->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            return $this->ok($reports);
        } catch (\Exception $e) {
            Log::error('Get history error', ['error' => $e->getMessage()]);
            return $this->error([500, '获取历史记录失败']);
        }
    }

    /**
     * 获取节点的平均性能
     */
    public function getNodeStats(PerformanceNodeStats $request)
    {
        $validated = $request->validated();
        try {
            $days = $validated['days'] ?? 7;
            $stats = NodePerformanceReport::getNodeAveragePerformance(
                $validated['node_id'],
                $days
            );

            return $this->ok([
                'node_id' => $validated['node_id'],
                'period_days' => $days,
                'avg_delay' => $stats->avg_delay ?? 0,
                'min_delay' => $stats->min_delay ?? 0,
                'max_delay' => $stats->max_delay ?? 0,
                'avg_success_rate' => $stats->avg_success_rate ?? 0,
                'report_count' => $stats->report_count ?? 0,
            ]);
        } catch (\Exception $e) {
            Log::error('Get node stats error', ['error' => $e->getMessage()]);
            return $this->error([500, '获取统计数据失败']);
        }
    }
}