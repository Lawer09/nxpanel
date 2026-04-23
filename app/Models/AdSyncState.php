<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdSyncState extends Model
{
    protected $table = 'ad_sync_state';

    protected $guarded = ['id'];

    public $timestamps = false; // 只有 updated_at，无 created_at

    protected $casts = [
        'last_success_at' => 'datetime',
        'last_started_at' => 'datetime',
        'last_sync_date'  => 'date',
        'updated_at'      => 'datetime',
    ];

    public const STATUS_IDLE    = 'idle';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FAILED  = 'failed';

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdPlatformAccount::class, 'account_id', 'id');
    }
}
