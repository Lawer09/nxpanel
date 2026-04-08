<?php

namespace App\Services;

use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\InviteGiftCardLog;
use App\Models\InviteGiftCardRule;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 邀请礼品卡服务
 * 
 * 负责在用户注册或消费时，自动为邀请人/被邀请人发放礼品卡
 */
class InviteGiftCardService
{
    /**
     * 注册触发：为新用户及其邀请人发放礼品卡
     */
    public function issueForRegister(User $newUser): array
    {
        // 检查是否有邀请人
        if (!$newUser->invite_user_id) {
            return ['issued' => 0, 'details' => []];
        }

        // 查询启用的注册触发规则
        $rules = InviteGiftCardRule::where('trigger_type', InviteGiftCardRule::TRIGGER_REGISTER)
            ->where('status', true)
            ->orderBy('sort')
            ->get();

        if ($rules->isEmpty()) {
            return ['issued' => 0, 'details' => []];
        }

        $results = [];
        foreach ($rules as $rule) {
            try {
                $issued = $this->processRule($rule, $newUser->invite_user_id, $newUser->id);
                $results = array_merge($results, $issued);
            } catch (\Exception $e) {
                Log::error('InviteGiftCard register issue failed', [
                    'rule_id' => $rule->id,
                    'new_user_id' => $newUser->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'issued' => count($results),
            'details' => $results
        ];
    }

    /**
     * 订单触发：为订单用户的邀请人发放礼品卡
     */
    public function issueForOrder(Order $order): array
    {
        // 检查订单用户是否有邀请人
        $user = $order->user;
        if (!$user || !$user->invite_user_id) {
            return ['issued' => 0, 'details' => []];
        }

        // 查询启用的订单触发规则
        $rules = InviteGiftCardRule::where('trigger_type', InviteGiftCardRule::TRIGGER_ORDER_PAID)
            ->where('status', true)
            ->orderBy('sort')
            ->get();

        if ($rules->isEmpty()) {
            return ['issued' => 0, 'details' => []];
        }

        $results = [];
        foreach ($rules as $rule) {
            try {
                // 检查规则是否匹配订单
                if (!$rule->matchesOrder($order)) {
                    continue;
                }

                $issued = $this->processRule($rule, $user->invite_user_id, $user->id, $order);
                $results = array_merge($results, $issued);
            } catch (\Exception $e) {
                Log::error('InviteGiftCard order issue failed', [
                    'rule_id' => $rule->id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'issued' => count($results),
            'details' => $results
        ];
    }

    /**
     * 处理单条规则
     */
    protected function processRule(
        InviteGiftCardRule $rule,
        int $inviterId,
        int $inviteeId,
        ?Order $order = null
    ): array {
        $results = [];

        // 获取接收用户ID列表
        $recipientUserIds = $rule->getRecipientUserIds($inviterId, $inviteeId);

        foreach ($recipientUserIds as $recipientUserId) {
            // 检查是否达到发放上限
            if ($rule->hasReachedLimit($recipientUserId)) {
                Log::info('InviteGiftCard limit reached', [
                    'rule_id' => $rule->id,
                    'recipient_user_id' => $recipientUserId
                ]);
                continue;
            }

            try {
                $result = $this->generateAndAssign($rule, $recipientUserId, $inviteeId, $order);
                $results[] = $result;
            } catch (\Exception $e) {
                Log::error('InviteGiftCard generate failed', [
                    'rule_id' => $rule->id,
                    'recipient_user_id' => $recipientUserId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $results;
    }

    /**
     * 生成兑换码并分配给用户
     */
    protected function generateAndAssign(
        InviteGiftCardRule $rule,
        int $recipientUserId,
        int $triggerUserId,
        ?Order $order = null
    ): array {
        return DB::transaction(function () use ($rule, $recipientUserId, $triggerUserId, $order) {
            // 加载模板
            $template = GiftCardTemplate::findOrFail($rule->template_id);

            // 生成兑换码
            $code = GiftCardCode::create([
                'template_id' => $template->id,
                'code' => GiftCardCode::generateCode('IGC'),
                'batch_id' => 'invite_' . $rule->id . '_' . time(),
                'status' => GiftCardCode::STATUS_UNUSED,
                'expires_at' => $rule->calculateExpiresAt(),
                'max_usage' => 1,
                'metadata' => [
                    'source' => 'invite_gift_card',
                    'rule_id' => $rule->id,
                    'trigger_type' => $rule->trigger_type,
                    'recipient_user_id' => $recipientUserId,
                    'trigger_user_id' => $triggerUserId,
                    'order_id' => $order?->id
                ]
            ]);

            $autoRedeemed = false;

            // 如果设置了自动兑换，立即兑换
            if ($rule->auto_redeem) {
                try {
                    $recipient = User::findOrFail($recipientUserId);
                    $giftCardService = new GiftCardService($code->code);
                    $giftCardService->setUser($recipient)
                        ->validate()
                        ->redeem([
                            'source' => 'invite_auto_redeem',
                            'rule_id' => $rule->id
                        ]);
                    $autoRedeemed = true;
                } catch (\Exception $e) {
                    Log::error('InviteGiftCard auto redeem failed', [
                        'code_id' => $code->id,
                        'recipient_user_id' => $recipientUserId,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // 记录发放日志
            $log = InviteGiftCardLog::createLog([
                'rule_id' => $rule->id,
                'trigger_type' => $rule->trigger_type,
                'trigger_user_id' => $triggerUserId,
                'recipient_user_id' => $recipientUserId,
                'code_id' => $code->id,
                'order_id' => $order?->id,
                'auto_redeemed' => $autoRedeemed,
                'metadata' => [
                    'template_name' => $template->name,
                    'rule_name' => $rule->name,
                    'order_amount' => $order?->total_amount
                ]
            ]);

            return [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'code_id' => $code->id,
                'code' => $code->code,
                'recipient_user_id' => $recipientUserId,
                'auto_redeemed' => $autoRedeemed,
                'log_id' => $log->id
            ];
        });
    }

    /**
     * 获取用户收到的邀请礼品卡统计
     */
    public function getUserStatistics(int $userId): array
    {
        $logs = InviteGiftCardLog::where('recipient_user_id', $userId)
            ->with(['rule', 'code'])
            ->get();

        return [
            'total_received' => $logs->count(),
            'auto_redeemed_count' => $logs->where('auto_redeemed', true)->count(),
            'pending_count' => $logs->where('auto_redeemed', false)->count(),
            'by_trigger_type' => [
                'register' => $logs->where('trigger_type', InviteGiftCardRule::TRIGGER_REGISTER)->count(),
                'order_paid' => $logs->where('trigger_type', InviteGiftCardRule::TRIGGER_ORDER_PAID)->count(),
            ]
        ];
    }

    /**
     * 获取规则发放统计
     */
    public function getRuleStatistics(int $ruleId): array
    {
        $logs = InviteGiftCardLog::where('rule_id', $ruleId)->get();

        return [
            'total_issued' => $logs->count(),
            'auto_redeemed_count' => $logs->where('auto_redeemed', true)->count(),
            'unique_recipients' => $logs->pluck('recipient_user_id')->unique()->count(),
            'unique_triggers' => $logs->pluck('trigger_user_id')->unique()->count(),
        ];
    }
}
