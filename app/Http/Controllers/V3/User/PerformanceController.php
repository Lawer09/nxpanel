<?php

namespace App\Http\Controllers\V3\User;

use App\Http\Controllers\Controller;
use App\Models\NodePerformanceReport;
use App\Services\NodePerformanceService;
use App\Services\IpInfoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PerformanceController extends Controller
{
    /**
     * 单个节点性能上报
     */
    public function report(Request $request)
    {
        $validated = $request->validate([
            'node_id' => 'required|integer',
            'delay' => 'required|integer|min:0|max:60000',
            'success_rate' => 'required|integer|min:0|max:100',
            'app_version' => 'nullable|string|max:50',
            'metadata' => 'nullable|json',
        ]);

        try {
            $userId = $userId = $request->user()->id;
            $clientIp = $request->getClientIp();

            $report = NodePerformanceService::reportPerformance(
                $userId,
                $validated['node_id'],
                $validated,
                $clientIp,
                $request
            );

            return $this->success([
                'id' => $report->id,
                'message' => '上报成功',
            ]);
        } catch (\Exception $e) {
            Log::error('Performance report error', ['error' => $e->getMessage()]);
            return $this->fail([500, '上报失败，请稍后重试']);
        }
    }

    /**
     * 批量节点性能上报
     */
    public function batchReport(Request $request)
    {
        $validated = $request->validate([
            'reports' => 'required|array|min:1|max:100',
            'reports.*.node_id' => 'required|integer',
            'reports.*.delay' => 'required|integer|min:0|max:60000',
            'reports.*.success_rate' => 'required|integer|min:0|max:100',
            'reports.*.app_version' => 'nullable|string|max:50',
            'reports.*.metadata' => 'nullable|json',
        ]);

        try {
            $user = auth('user')->user();
            if (!$user) {
                return $this->error([401, '未授权']);
            }
            
            $userId = $user->id;
            $clientIp = $request->getClientIp();

            $results = NodePerformanceService::batchReportPerformance(
                $userId,
                $validated['reports'],
                $clientIp,
                $request
            );

            return $this->ok([
                'count' => count($results),
                'message' => '批量上报成功',
            ]);
        } catch (\Exception $e) {
            Log::error('Batch performance report error', ['error' => $e->getMessage()]);
            return $this->error([500, '批量上报失败，请稍后重试']);
        }
    }

    /**
     * 获取当前客户端IP信息
     */
    public function getClientIpInfo(Request $request)
    {
        try {
            $clientIp = $request->getClientIp();
            $ipInfo = IpInfoService::getIpInfo($clientIp);

            return $this->success($ipInfo);
        } catch (\Exception $e) {
            Log::error('Get client IP info error', ['error' => $e->getMessage()]);
            return $this->fail([500, $e->getMessage()]);
        }
    }

    /**
     * 获取用户的上报历史
     */
    public function getHistory(Request $request)
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:1000',
            'node_id' => 'nullable|integer',
        ]);

        try {
            $user = auth('user')->user();
            if (!$user) {
                return $this->fail([401, '未授权']);
            }
            
            $userId = $user->id;
            $limit = $validated['limit'] ?? 100;

            $query = NodePerformanceReport::where('user_id', $userId);

            if ($request->filled('node_id')) {
                $query->where('node_id', $validated['node_id']);
            }

            $reports = $query->orderByDesc('created_at')
                ->limit($limit)
                ->get();

            return $this->success($reports);
        } catch (\Exception $e) {
            Log::error('Get history error', ['error' => $e->getMessage()]);
            return $this->fail([500, '获取历史记录失败']);
        }
    }

    /**
     * 获取节点的平均性能
     */
    public function getNodeStats(Request $request)
    {
        $validated = $request->validate([
            'node_id' => 'required|integer',
            'days' => 'nullable|integer|min:1|max:90',
        ]);

        try {
            $days = $validated['days'] ?? 7;
            $stats = NodePerformanceReport::getNodeAveragePerformance(
                $validated['node_id'],
                $days
            );

            return $this->success([
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
            return $this->fail([500, '获取统计数据失败']);
        }
    }
}