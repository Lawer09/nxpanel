<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficPlatformUsageStat extends Model
{
    protected $table = 'traffic_platform_usage_stat';

    protected $guarded = ['id'];

    protected $casts = [
        'traffic_bytes' => 'integer',
        'traffic_mb'    => 'decimal:6',
        'traffic_gb'    => 'decimal:6',
        'is_estimated'  => 'integer',
        'stat_hour'     => 'datetime',
        'stat_date'     => 'date',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(TrafficPlatformAccount::class, 'platform_account_id');
    }
}
