<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpPool extends Model
{
    protected $table = 'v2_ip_pool';
    public $timestamps = true;  // 启用自动时间戳管理

    protected $fillable = [
        'ip',
        'machine_id',
        'hostname',
        'city',
        'region',
        'country',
        'loc',
        'org',
        'postal',
        'timezone',
        'readme_url',
        'metadata',
        'score',
        'load',
        'max_load',
        'success_rate',
        'status',
        'risk_level',
        'total_requests',
        'successful_requests',
        'last_used_at',
    ];

    protected $casts = [
        'machine_id' => 'integer',
        'metadata' => 'array',
        'score' => 'integer',
        'load' => 'integer',
        'success_rate' => 'integer',
        'risk_level' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * 获取可用的IP
     */
    public static function getAvailableIps()
    {
        return self::where('status', 'active')
            ->where('risk_level', '<', 50)
            ->where('success_rate', '>', 80)
            ->orderByDesc('score')
            ->orderBy('load')
            ->get();
    }

    /**
     * 更新使用统计
     */
    public function recordUsage($success = true)
    {
        $this->total_requests++;
        if ($success) {
            $this->successful_requests++;
        }
        $this->last_used_at = time();
        $this->success_rate = (int) ($this->successful_requests / $this->total_requests * 100);
        $this->save();
    }

    /**
     * 更新冷却状态
     */
    public function setCooldown($duration = 3600)
    {
        $this->status = 'cooldown';
        $this->save();
    }
}