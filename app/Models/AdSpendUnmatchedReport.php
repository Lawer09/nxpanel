<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdSpendUnmatchedReport extends Model
{
    protected $table = 'ad_spend_platform_unmatched_reports';

    protected $guarded = ['id'];

    protected $casts = [
        'impressions'  => 'integer',
        'clicks'       => 'integer',
        'spend'        => 'decimal:6',
        'ctr'          => 'decimal:6',
        'cpm'          => 'decimal:6',
        'cpc'          => 'decimal:6',
        'raw_data'     => 'array',
        'report_date'  => 'date',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];
}
