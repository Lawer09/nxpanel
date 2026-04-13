<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SshKey extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'tags',
        'provider_id',
        'provider_key_id',
        'secret_key',
        'note',
    ];

    protected $hidden = [
        'secret_key',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 获取加密的密钥
     */
    public function getSecretKeyAttribute($value)
    {
        return $value ? decrypt($value) : null;
    }

    /**
     * 设置加密的密钥
     */
    public function setSecretKeyAttribute($value)
    {
        $this->attributes['secret_key'] = $value ? encrypt($value) : null;
    }

    /**
     * 关联云服务商
     */
    public function provider()
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }
}
