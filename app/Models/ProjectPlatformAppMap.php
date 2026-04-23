<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectPlatformAppMap extends Model
{
    protected $table = 'project_platform_app_map';

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const STATUS_ENABLED  = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    // ── 关联 ──────────────────────────────────
    public function account(): BelongsTo
    {
        return $this->belongsTo(AdPlatformAccount::class, 'account_id', 'id');
    }

    // ── 查询作用域 ─────────────────────────────
    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }
}
