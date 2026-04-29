<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectUserAppMap extends Model
{
    protected $table = 'project_user_app_map';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
