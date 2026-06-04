<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficPlatformUsageHourly extends Model
{
    protected $table = 'traffic_platform_usage_hourly';

    protected $guarded = ['id'];

    protected $casts = [
        'report_date' => 'date',
        'report_hour' => 'datetime',
        'traffic_bytes' => 'integer',
        'traffic_mb' => 'decimal:6',
        'baseline_snapshot_time' => 'datetime',
        'current_snapshot_time' => 'datetime',
        'is_anomaly' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(TrafficPlatformAccount::class, 'platform_account_id');
    }
}
