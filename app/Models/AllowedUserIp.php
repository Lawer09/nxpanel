<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllowedUserIp extends Model
{
    protected $table = 'allowed_user_ips';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function operatorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id', 'id');
    }
}
