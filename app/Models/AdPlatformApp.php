<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdPlatformApp extends Model
{
    protected $table = 'ad_platform_app';

    protected $guarded = ['id'];

    protected $casts = [
        'raw_json'   => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ── 关联 ──────────────────────────────────
    public function account(): BelongsTo
    {
        return $this->belongsTo(AdPlatformAccount::class, 'account_id', 'id');
    }
}
