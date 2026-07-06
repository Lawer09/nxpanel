<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $table = 'project_projects';

    protected $guarded = ['id'];

    protected $casts = [
        'owner_id'   => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public function trafficAccounts(): HasMany
    {
        return $this->hasMany(ProjectTrafficPlatformAccount::class, 'project_id');
    }

    public function adAccounts(): HasMany
    {
        return $this->hasMany(ProjectAdPlatformAccount::class, 'project_id');
    }

    public function userApps(): HasMany
    {
        return $this->hasMany(ProjectUserAppMap::class, 'project_code', 'project_code');
    }
}
