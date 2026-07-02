<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AidChannelTypeUpdateQueue extends Model
{
    protected $table = 'aid_channel_type_update_queues';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'last_login_at' => 'integer',
        'attempts' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
