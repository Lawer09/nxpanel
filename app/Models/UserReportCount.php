<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserReportCount extends Model
{
    protected $table = 'v3_user_report_count';

    public $timestamps = false;

    protected $fillable = [
        'date',
        'hour',
        'minute',
        'user_id',
        'report_count',
        'node_count',
        'client_country',
        'client_isp',
        'platform',
        'app_id',
        'app_version',
        'created_at',
    ];

    protected $casts = [
        'date'         => 'date:Y-m-d',
        'hour'         => 'integer',
        'minute'       => 'integer',
        'user_id'      => 'integer',
        'report_count' => 'integer',
        'node_count'   => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
