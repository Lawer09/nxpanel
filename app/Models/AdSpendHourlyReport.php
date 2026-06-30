<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdSpendHourlyReport extends Model
{
    protected $table = 'ad_spend_report_hourly';

    protected $guarded = ['id'];

    protected $casts = [
        'report_date' => 'date',
        'hour' => 'integer',
        'group_id' => 'integer',
        'user_id' => 'integer',
        'agency_id' => 'integer',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'spend' => 'decimal:6',
        'ctr' => 'decimal:6',
        'cpm' => 'decimal:6',
        'cpc' => 'decimal:6',
        'roas' => 'decimal:6',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
