<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTrafficPlatformAccount extends Model
{
    protected $table = 'project_traffic_platform_accounts';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled'    => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function trafficAccount(): BelongsTo
    {
        return $this->belongsTo(TrafficPlatformAccount::class, 'traffic_platform_account_id');
    }
}
