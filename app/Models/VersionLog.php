<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VersionLog extends Model
{
    protected $table = 'v3_version_logs';

    protected $fillable = [
        'version',
        'title',
        'description',
        'features',
        'improvements',
        'bugfixes',
        'release_date',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'features'     => 'array',
        'improvements' => 'array',
        'bugfixes'     => 'array',
        'release_date' => 'date:Y-m-d',
        'is_published' => 'boolean',
        'sort_order'   => 'integer',
    ];
}
