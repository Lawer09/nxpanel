<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\InviteGiftCardRule
 *
 * @property int $id
 * @property string $name 规则名称
 * @property string $trigger_type 触发类型
 * @property int $template_id 礼品卡模板ID
 * @property string $target 发放对象
 * @property bool $auto_redeem 是否自动兑换
 * @property int $min_order_amount 最低订单金额
 * @property int|null $order_type 订单类型
 * @property int $max_issue_per_user 每个邀请人最多发放次数
 * @property int|null $expires_hours 兑换码有效期
 * @property bool $status 状态
 * @property int $sort 排序
 * @property string|null $description 规则描述
 * @property int $created_at
 * @property int $updated_at
 */
class InviteGiftCardRule extends Model
{
    protected $table = 'v2_invite_gift_card_rules';
    protected $dateFormat = 'U';

    // 触发类型常量
    const TRIGGER_REGISTER = 'register';
    const TRIGGER_ORDER_PAID = 'order_paid';

    // 发放对象常量
    const TARGET_INVITER = 'inviter';
    const TARGET_INVITEE = 'invitee';
    const TARGET_BOTH = 'both';

    // 订单类型常量
    const ORDER_TYPE_NEW = 1;       // 新购
    const ORDER_TYPE_RENEW = 2;     // 续费
    const ORDER_TYPE_UPGRADE = 3;   // 升级

    protected $fillable = [
        'name',
        'trigger_type',
        'template_id',
        'target',
        'auto_redeem',
        'min_order_amount',
        'order_type',
        'max_issue_per_user',
        'expires_hours',
        'status',
        'sort',
        'description'
    ];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'auto_redeem' => 'boolean',
        'status' => 'boolean',
        'min_order_amount' => 'integer',
        'max_issue_per_user' => 'integer',
        'sort' => 'integer'
    ];

    /**
     * 获取触发类型映射
     */
    public static function getTriggerTypeMap(): array
    {
        return [
            self::TRIGGER_REGISTER => '注册',
            self::TRIGGER_ORDER_PAID => '订单支付',
        ];
    }

    /**
     * 获取发放对象映射
     */
    public static function getTargetMap(): array
    {
        return [
            self::TARGET_INVITER => '邀请人',
            self::TARGET_INVITEE => '被邀请人',
            self::TARGET_BOTH => '双方',
        ];
    }

    /**
     * 获取订单类型映射
     */
    public static function getOrderTypeMap(): array
    {
        return [
            self::ORDER_TYPE_NEW => '新购',
            self::ORDER_TYPE_RENEW => '续费',
            self::ORDER_TYPE_UPGRADE => '升级',
        ];
    }

    /**
     * 关联礼品卡模板
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(GiftCardTemplate::class, 'template_id');
    }

    /**
     * 关联发放日志
     */
    public function logs(): HasMany
    {
        return $this->hasMany(InviteGiftCardLog::class, 'rule_id');
    }

    /**
     * 检查规则是否匹配订单
     */
    public function matchesOrder($order): bool
    {
        // 检查最低金额
        if ($this->min_order_amount > 0 && $order->total_amount < $this->min_order_amount) {
            return false;
        }

        // 检查订单类型
        if ($this->order_type !== null && $order->type != $this->order_type) {
            return false;
        }

        return true;
    }

    /**
     * 检查用户是否达到发放上限
     */
    public function hasReachedLimit(int $userId): bool
    {
        if ($this->max_issue_per_user == 0) {
            return false;
        }

        $count = InviteGiftCardLog::where('rule_id', $this->id)
            ->where('recipient_user_id', $userId)
            ->count();

        return $count >= $this->max_issue_per_user;
    }

    /**
     * 获取发放对象用户ID列表
     */
    public function getRecipientUserIds(int $inviterId, int $inviteeId): array
    {
        return match ($this->target) {
            self::TARGET_INVITER => [$inviterId],
            self::TARGET_INVITEE => [$inviteeId],
            self::TARGET_BOTH => [$inviterId, $inviteeId],
            default => []
        };
    }

    /**
     * 计算兑换码过期时间
     */
    public function calculateExpiresAt(): ?int
    {
        if ($this->expires_hours === null) {
            return null;
        }

        return time() + ($this->expires_hours * 3600);
    }
}
