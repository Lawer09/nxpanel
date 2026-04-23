<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdPlatformAdUnit extends Model
{
    protected $table = 'ad_platform_ad_unit';

    protected $guarded = ['id'];

    protected $casts = [
        'ad_types_json' => 'array',
        'raw_json'      => 'array',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AdPlatformAccount::class, 'account_id', 'id');
    }
}
