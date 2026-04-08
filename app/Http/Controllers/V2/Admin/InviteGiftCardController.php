<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\GiftCardTemplate;
use App\Models\InviteGiftCardLog;
use App\Models\InviteGiftCardRule;
use App\Services\InviteGiftCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InviteGiftCardController extends Controller
{
    /**
     * 获取规则列表
     */
    public function fetchRules(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $query = InviteGiftCardRule::with('template');

        // 过滤
        if ($request->filled('trigger_type')) {
            $query->where('trigger_type', $request->input('trigger_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $total = $query->count();
        $items = $query->orderBy('sort')
            ->orderByDesc('created_at')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        // 附加统计信息
        $service = app(InviteGiftCardService::class);
        $items->each(function ($rule) use ($service) {
            $rule->statistics = $service->getRuleStatistics($rule->id);
        });

        return $this->ok([
            'data' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'page' => $current
        ]);
    }

    /**
     * 获取规则详情
     */
    public function detailRule(Request $request)
    {
        $id = $request->input('id');
        $rule = InviteGiftCardRule::with('template')->find($id);

        if (!$rule) {
            return $this->error([404, '规则不存在']);
        }

        $service = app(InviteGiftCardService::class);
        $rule->statistics = $service->getRuleStatistics($rule->id);

        return $this->ok($rule);
    }

    /**
     * 创建/编辑规则
     */
    public function saveRule(Request $request)
    {
        $validated = $request->validate([
            'id' => 'nullable|integer|exists:v2_invite_gift_card_rules,id',
            'name' => 'required|string|max:255',
            'trigger_type' => 'required|in:register,order_paid',
            'template_id' => 'required|integer|exists:v2_gift_card_template,id',
            'target' => 'required|in:inviter,invitee,both',
            'auto_redeem' => 'required|boolean',
            'min_order_amount' => 'nullable|integer|min:0',
            'order_type' => 'nullable|integer|in:1,2,3',
            'max_issue_per_user' => 'nullable|integer|min:0',
            'expires_hours' => 'nullable|integer|min:1',
            'status' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:0',
            'description' => 'nullable|string'
        ]);

        try {
            if ($request->input('id')) {
                // 编辑
                $rule = InviteGiftCardRule::findOrFail($request->input('id'));
                $rule->update($validated);
                return $this->ok($rule);
            } else {
                // 新增
                $validated['status'] = $validated['status'] ?? true;
                $validated['sort'] = $validated['sort'] ?? 0;
                $rule = InviteGiftCardRule::create($validated);
                return $this->ok($rule);
            }
        } catch (\Exception $e) {
            Log::error('InviteGiftCard save rule failed', ['error' => $e->getMessage()]);
            return $this->error([500, '保存失败: ' . $e->getMessage()]);
        }
    }

    /**
     * 切换规则状态
     */
    public function toggleRule(Request $request)
    {
        $id = $request->input('id');
        $rule = InviteGiftCardRule::find($id);

        if (!$rule) {
            return $this->error([404, '规则不存在']);
        }

        $rule->status = !$rule->status;
        $rule->save();

        return $this->ok([
            'id' => $rule->id,
            'status' => $rule->status
        ]);
    }

    /**
     * 删除规则
     */
    public function deleteRule(Request $request)
    {
        $id = $request->input('id');
        $rule = InviteGiftCardRule::find($id);

        if (!$rule) {
            return $this->error([404, '规则不存在']);
        }

        // 检查是否有关联的发放记录
        $logCount = InviteGiftCardLog::where('rule_id', $id)->count();
        if ($logCount > 0) {
            return $this->error([422, "该规则已有 {$logCount} 条发放记录，无法删除"]);
        }

        $rule->delete();

        return $this->ok(true);
    }

    /**
     * 批量删除规则
     */
    public function batchDeleteRules(Request $request)
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return $this->error([422, '请选择要删除的规则']);
        }

        $rules = InviteGiftCardRule::whereIn('id', $ids)->get();
        $deleted = [];
        $failed = [];

        foreach ($rules as $rule) {
            $logCount = InviteGiftCardLog::where('rule_id', $rule->id)->count();
            if ($logCount > 0) {
                $failed[] = [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'reason' => "已有 {$logCount} 条发放记录"
                ];
            } else {
                $rule->delete();
                $deleted[] = $rule->id;
            }
        }

        return $this->ok([
            'deleted' => $deleted,
            'failed' => $failed,
            'deleted_count' => count($deleted),
            'failed_count' => count($failed)
        ]);
    }

    /**
     * 获取发放日志
     */
    public function fetchLogs(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $query = InviteGiftCardLog::with([
            'rule',
            'triggerUser:id,email',
            'recipientUser:id,email',
            'code',
            'order:id,trade_no,total_amount'
        ]);

        // 过滤
        if ($request->filled('rule_id')) {
            $query->where('rule_id', $request->input('rule_id'));
        }

        if ($request->filled('trigger_type')) {
            $query->where('trigger_type', $request->input('trigger_type'));
        }

        if ($request->filled('recipient_user_id')) {
            $query->where('recipient_user_id', $request->input('recipient_user_id'));
        }

        if ($request->filled('auto_redeemed')) {
            $query->where('auto_redeemed', $request->input('auto_redeemed'));
        }

        if ($request->filled('date_range')) {
            $dateRange = $request->input('date_range');
            if (isset($dateRange[0]) && isset($dateRange[1])) {
                $query->whereBetween('created_at', [
                    strtotime($dateRange[0]),
                    strtotime($dateRange[1])
                ]);
            }
        }

        $total = $query->count();
        $items = $query->orderByDesc('created_at')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->ok([
            'data' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'page' => $current
        ]);
    }

    /**
     * 获取统计数据
     */
    public function statistics(Request $request)
    {
        $totalRules = InviteGiftCardRule::count();
        $activeRules = InviteGiftCardRule::where('status', true)->count();
        $totalLogs = InviteGiftCardLog::count();
        $autoRedeemedCount = InviteGiftCardLog::where('auto_redeemed', true)->count();

        // 按触发类型统计
        $byTriggerType = InviteGiftCardLog::selectRaw('trigger_type, COUNT(*) as count')
            ->groupBy('trigger_type')
            ->get()
            ->pluck('count', 'trigger_type');

        // 最近7天发放趋势
        $recentDays = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $startTime = strtotime($date . ' 00:00:00');
            $endTime = strtotime($date . ' 23:59:59');

            $count = InviteGiftCardLog::whereBetween('created_at', [$startTime, $endTime])->count();
            $recentDays[] = [
                'date' => $date,
                'count' => $count
            ];
        }

        // Top 5 规则
        $topRules = InviteGiftCardRule::withCount('logs')
            ->orderByDesc('logs_count')
            ->limit(5)
            ->get()
            ->map(function ($rule) {
                return [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'count' => $rule->logs_count
                ];
            });

        return $this->ok([
            'total_rules' => $totalRules,
            'active_rules' => $activeRules,
            'total_issued' => $totalLogs,
            'auto_redeemed_count' => $autoRedeemedCount,
            'pending_count' => $totalLogs - $autoRedeemedCount,
            'by_trigger_type' => [
                'register' => $byTriggerType['register'] ?? 0,
                'order_paid' => $byTriggerType['order_paid'] ?? 0,
            ],
            'recent_days' => $recentDays,
            'top_rules' => $topRules
        ]);
    }

    /**
     * 获取可用的礼品卡模板列表
     */
    public function fetchTemplates(Request $request)
    {
        $templates = GiftCardTemplate::where('status', true)
            ->orderBy('sort')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'type', 'description', 'icon', 'theme_color']);

        return $this->ok($templates);
    }

    /**
     * 获取配置选项
     */
    public function getOptions(Request $request)
    {
        return $this->ok([
            'trigger_types' => InviteGiftCardRule::getTriggerTypeMap(),
            'targets' => InviteGiftCardRule::getTargetMap(),
            'order_types' => InviteGiftCardRule::getOrderTypeMap(),
        ]);
    }
}
