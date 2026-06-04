<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficPlatformUsageDaily extends Model
{
    protected $table = 'traffic_platform_usage_daily';

    protected $guarded = ['id'];

    protected $casts = [
        'report_date' => 'date',
        'snapshot_time' => 'datetime',
        'traffic_bytes_cum' => 'integer',
        'traffic_mb_cum' => 'decimal:6',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(TrafficPlatformAccount::class, 'platform_account_id');
    }
}
