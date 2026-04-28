<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficPlatformSyncJob extends Model
{
    protected $table = 'traffic_platform_sync_jobs';

    protected $guarded = ['id'];

    protected $casts = [
        'request_params'   => 'array',
        'response_summary' => 'array',
        'start_time'       => 'datetime',
        'end_time'         => 'datetime',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    public function account(): BelongsTo
    {
        return $this->belongsTo(TrafficPlatformAccount::class, 'platform_account_id');
    }
}
