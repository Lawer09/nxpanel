<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdSpendPlatformAccount extends Model
{
    protected $table = 'ad_spend_platform_accounts';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled'          => 'integer',
        'token_expired_at' => 'datetime',
        'last_sync_at'     => 'datetime',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    protected $hidden = ['password', 'access_token'];
}
