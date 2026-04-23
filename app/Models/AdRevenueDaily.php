<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdRevenueDaily extends Model
{
    protected $table = 'ad_revenue_daily';

    protected $guarded = ['id'];

    protected $casts = [
        'report_date'     => 'date',
        'match_rate'      => 'decimal:6',
        'show_rate'       => 'decimal:6',
        'ctr'             => 'decimal:6',
        'estimated_earnings' => 'decimal:6',
        'ecpm'            => 'decimal:6',
        'raw_header_json' => 'array',
        'raw_row_json'    => 'array',
        'sync_time'       => 'datetime',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdPlatformAccount::class, 'account_id', 'id');
    }
}
