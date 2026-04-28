<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficPlatformDailySnapshot extends Model
{
    protected $table = 'traffic_platform_daily_snapshots';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'total_bytes'   => 'integer',
        'total_gb'      => 'decimal:6',
        'stat_date'     => 'date',
        'snapshot_time' => 'datetime',
        'created_at'    => 'datetime',
    ];
}
