<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedUserIp extends Model
{
    public const TYPE_NORMAL = 'normal';
    public const TYPE_DANGEROUS = 'dangerous';

    protected $table = 'blocked_user_ips';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function bannedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_user_id', 'id');
    }

    public function operatorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id', 'id');
    }
}
