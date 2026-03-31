<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerTemplate extends Model
{
    protected $table = 'v2_server_template';

    protected $guarded = ['id'];

    protected $casts = [
        'group_ids'         => 'array',
        'route_ids'         => 'array',
        'tags'              => 'array',
        'excludes'          => 'array',
        'ips'               => 'array',
        'protocol_settings' => 'array',
        'custom_outbounds'  => 'array',
        'custom_routes'     => 'array',
        'cert_config'       => 'array',
        'rate_time_ranges'  => 'array',
        'show'              => 'boolean',
        'is_default'        => 'boolean',
        'rate_time_enable'  => 'boolean',
    ];

    /**
     * 将模板转换为可直接用于创建节点的配置数组（去除模板专有字段）
     */
    public function toServerConfig(): array
    {
        return collect($this->toArray())
            ->except(['id', 'name', 'description', 'is_default', 'created_at', 'updated_at'])
            ->filter(fn($v) => $v !== null)
            ->all();
    }
}
