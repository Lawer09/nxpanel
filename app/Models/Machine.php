<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Machine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'hostname',
        'ip_address',
        'private_ip_address',
        'port',
        'username',
        'password',
        'private_key',
        'status',
        'os_type',
        'cpu_cores',
        'memory',
        'disk',
        'last_check_at',
        'description',
        'is_active',
        'gpu_info',
        'bandwidth',
        'provider',
        'price',
        'pay_mode',
        'tags',
        'provider_instance_id',
        'provider_nic_id',
    ];

    protected $casts = [
        'last_check_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
        'private_key',
    ];

    /**
     * 获取加密的密码
     */
    public function getPasswordAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }

    /**
     * 设置加密的密码
     */
    public function setPasswordAttribute($value)
    {
        $this->attributes['password'] = $value ? encrypt($value) : null;
    }

    /**
     * 获取加密的私钥
     */
    public function getPrivateKeyAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }

    /**
     * 设置加密的私钥
     */
    public function setPrivateKeyAttribute($value)
    {
        $this->attributes['private_key'] = $value ? encrypt($value) : null;
    }

    /**
     * 范围查询：活跃的机器
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    /**
     * 范围查询：在线的机器
     */
    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }

    /**
     * 关联的所有IP（多对多）
     */
    public function ips()
    {
        return $this->belongsToMany(IpPool::class, 'ip_machine', 'machine_id', 'ip_id')
            ->using(IpMachine::class)
            ->withPivot(['is_primary', 'is_egress', 'bind_status', 'bound_at', 'unbound_at'])
            ->withTimestamps();
    }

    /**
     * 获取主IP（向后兼容）
     */
    public function boundIp()
    {
        return $this->ips()->wherePivot('is_primary', true)->wherePivot('bind_status', 'active');
    }

    /**
     * 获取主IP记录（单个对象）
     */
    public function getPrimaryIpAttribute()
    {
        return $this->ips()->wherePivot('is_primary', true)->wherePivot('bind_status', 'active')->first();
    }

    /**
     * 获取所有活跃的IP
     */
    public function activeIps()
    {
        return $this->ips()->wherePivot('bind_status', 'active');
    }
}