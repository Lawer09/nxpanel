<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IpPool extends Model
{
    protected $table = 'v2_ip_pool';
    public $timestamps = true;  // 启用自动时间戳管理

    protected $fillable = [
        'ip',
        'bandwidth',
        'hostname',
        'city',
        'region',
        'country',
        'loc',
        'org',
        'postal',
        'timezone',
        'readme_url',
        'provider_id',
        'provider_ip_id',
        'ip_type',
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
        'bandwidth' => 'integer',
        'provider_id' => 'integer',
        'provider_ip_id' => 'string',
        'ip_type' => 'string',
        'metadata' => 'array',
        'score' => 'integer',
        'load' => 'integer',
        'success_rate' => 'integer',
        'risk_level' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    /**
     * 关联供应商
     */
    public function provider()
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

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

    /**
     * 关联的所有机器（多对多）
     */
    public function machines()
    {
        return $this->belongsToMany(Machine::class, 'ip_machine', 'ip_id', 'machine_id')
            ->using(IpMachine::class)
            ->withPivot(['is_primary', 'is_egress', 'bind_status', 'bound_at', 'unbound_at'])
            ->withTimestamps();
    }

    /**
     * 获取当前绑定的机器（活跃状态）
     */
    public function activeMachines()
    {
        return $this->machines()->wherePivot('bind_status', 'active');
    }

    /**
     * 获取主绑定的机器
     */
    public function primaryMachine()
    {
        return $this->machines()->wherePivot('is_primary', true)->wherePivot('bind_status', 'active');
    }
}