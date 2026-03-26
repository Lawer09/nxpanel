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
}