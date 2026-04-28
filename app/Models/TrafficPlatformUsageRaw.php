<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrafficPlatformUsageRaw extends Model
{
    protected $table = 'traffic_platform_usage_raw';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'raw_data'   => 'array',
        'created_at' => 'datetime',
    ];
}
