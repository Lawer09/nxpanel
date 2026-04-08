<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\InviteGiftCardLog
 *
 * @property int $id
 * @property int $rule_id 规则ID
 * @property string $trigger_type 触发类型
 * @property int $trigger_user_id 触发用户ID
 * @property int $recipient_user_id 接收用户ID
 * @property int $code_id 生成的兑换码ID
 * @property int|null $order_id 关联订单ID
 * @property bool $auto_redeemed 是否已自动兑换
 * @property array|null $metadata 额外数据
 * @property int $created_at
 */
class InviteGiftCardLog extends Model
{
    protected $table = 'v2_invite_gift_card_logs';
    protected $dateFormat = 'U';
    public $timestamps = false;

    protected $fillable = [
        'rule_id',
        'trigger_type',
        'trigger_user_id',
        'recipient_user_id',
        'code_id',
        'order_id',
        'auto_redeemed',
        'metadata',
        'created_at'
    ];

    protected $casts = [
        'created_at' => 'timestamp',
        'auto_redeemed' => 'boolean',
        'metadata' => 'array'
    ];

    /**
     * 关联规则
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(InviteGiftCardRule::class, 'rule_id');
    }

    /**
     * 关联触发用户
     */
    public function triggerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trigger_user_id');
    }

    /**
     * 关联接收用户
     */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * 关联兑换码
     */
    public function code(): BelongsTo
    {
        return $this->belongsTo(GiftCardCode::class, 'code_id');
    }

    /**
     * 关联订单
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * 创建日志记录
     */
    public static function createLog(array $data): self
    {
        $data['created_at'] = $data['created_at'] ?? time();
        return self::create($data);
    }
}
