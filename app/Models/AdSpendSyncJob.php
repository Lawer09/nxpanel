<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdSpendSyncJob extends Model
{
    protected $table = 'ad_spend_platform_sync_jobs';

    protected $guarded = ['id'];

    protected $casts = [
        'total_records'     => 'integer',
        'matched_records'   => 'integer',
        'unmatched_records' => 'integer',
        'request_params'    => 'array',
        'start_date'        => 'date',
        'end_date'          => 'date',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
}
