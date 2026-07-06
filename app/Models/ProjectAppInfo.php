<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectAppInfo extends Model
{
    protected $table = 'app_infos';

    protected $guarded = ['id'];

    protected $casts = [
        'download_count' => 'integer',
        'download_data' => 'array',
        'image_urls' => 'array',
        'enabled' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
