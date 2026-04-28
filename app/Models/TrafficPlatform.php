<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrafficPlatform extends Model
{
    protected $table = 'traffic_platform_platforms';

    protected $guarded = ['id'];

    protected $casts = [
        'supports_hourly' => 'integer',
        'enabled'         => 'integer',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(TrafficPlatformAccount::class, 'platform_id');
    }
}
