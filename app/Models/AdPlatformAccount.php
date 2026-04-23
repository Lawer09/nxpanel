<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdPlatformAccount extends Model
{
    protected $table = 'ad_platform_account';

    protected $guarded = ['id'];

    protected $casts = [
        'tags'       => 'array',
        'ext_json'   => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [];

    /**
     * 获取凭据（JSON 字符串转数组）
     */
    public function getCredentialsJsonAttribute($value)
    {
        if (!$value) {
            return null;
        }
        return json_decode($value, true);
    }

    /**
     * 设置凭据（数组转 JSON 字符串）
     */
    public function setCredentialsJsonAttribute($value)
    {
        if (is_array($value)) {
            $value = json_encode($value);
        }
        $this->attributes['credentials_json'] = $value ?: null;
    }

    // ── 状态常量 ──────────────────────────────
    public const STATUS_ENABLED  = 'enabled';
    public const STATUS_DISABLED = 'disabled';

    public const AUTH_TYPE_OAUTH       = 'oauth';
    public const AUTH_TYPE_SERVICE_KEY = 'service_key';

    public static array $statusMap = [
        self::STATUS_ENABLED  => '启用',
        self::STATUS_DISABLED => '停用',
    ];

    // ── 关联 ──────────────────────────────────
    public function apps(): HasMany
    {
        return $this->hasMany(AdPlatformApp::class, 'account_id', 'id');
    }

    public function adUnits(): HasMany
    {
        return $this->hasMany(AdPlatformAdUnit::class, 'account_id', 'id');
    }

    public function revenueDailies(): HasMany
    {
        return $this->hasMany(AdRevenueDaily::class, 'account_id', 'id');
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(AdSyncState::class, 'account_id', 'id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(AdSyncLog::class, 'account_id', 'id');
    }

    // ── 查询作用域 ─────────────────────────────
    public function scopeEnabled($query)
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    public function scopeByPlatform($query, string $platform)
    {
        return $query->where('source_platform', $platform);
    }

    public function scopeByServer($query, string $serverId)
    {
        return $query->where('assigned_server_id', $serverId);
    }

    /**
     * 是否有下游数据依赖（禁止删除）
     */
    public function hasDependencies(): bool
    {
        return $this->apps()->exists()
            || $this->adUnits()->exists()
            || $this->revenueDailies()->exists();
    }
}
