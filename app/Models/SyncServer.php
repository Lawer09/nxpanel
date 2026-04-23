<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncServer extends Model
{
    protected $table = 'sync_server';

    protected $guarded = ['id'];

    protected $casts = [
        'tags'              => 'array',
        'capabilities'      => 'array',
        'last_heartbeat_at' => 'datetime',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
    ];

    public const STATUS_ONLINE      = 'online';
    public const STATUS_OFFLINE     = 'offline';
    public const STATUS_MAINTENANCE = 'maintenance';

    public static array $statusMap = [
        self::STATUS_ONLINE      => '在线',
        self::STATUS_OFFLINE     => '离线',
        self::STATUS_MAINTENANCE => '维护中',
    ];

    // ── 关联 ──────────────────────────────────
    public function accounts(): HasMany
    {
        return $this->hasMany(AdPlatformAccount::class, 'assigned_server_id', 'server_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(AdSyncLog::class, 'server_id', 'server_id');
    }

    // ── 查询作用域 ─────────────────────────────
    public function scopeOnline($query)
    {
        return $query->where('status', self::STATUS_ONLINE);
    }
}
