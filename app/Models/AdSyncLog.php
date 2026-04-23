<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdSyncLog extends Model
{
    protected $table = 'ad_sync_log';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'request_summary' => 'array',
        'started_at'      => 'datetime',
        'ended_at'        => 'datetime',
    ];

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_RUNNING = 'running';

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdPlatformAccount::class, 'account_id', 'id');
    }
}
