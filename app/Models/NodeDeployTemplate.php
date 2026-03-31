<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 节点部署配置模板
 *
 * @property int         $id
 * @property string      $name              模板名称
 * @property string|null $description       模板描述
 * @property string      $node_type         协议类型
 * @property string|null $node_type2        子类型（如 reality）
 * @property int         $core_type         核心类型 1=xray 2=singbox 3=hysteria2
 * @property string      $node_inout_type   stand/in/out
 * @property array|null  $group_ids         权限组ID列表
 * @property array|null  $route_ids         路由ID列表
 * @property array|null  $tags              节点标签
 * @property bool        $show              前台显示
 * @property string      $rate              倍率
 * @property int         $tls               TLS 模式
 * @property string|null $server_name       伪装站点
 * @property string|null $flow              VLESS flow
 * @property string      $network           传输网络
 * @property array|null  $network_settings  传输层设置
 * @property string      $cert_mode         证书模式
 * @property string|null $cert_domain       证书域名
 * @property string|null $release_repo      Release 仓库
 * @property string|null $script_repo       脚本仓库
 * @property string|null $script_branch     脚本分支
 * @property string|null $github_token      GitHub Token
 * @property string|null $cipher            SS 加密
 * @property string|null $plugin            SS 插件
 * @property string|null $plugin_opts       SS 插件参数
 * @property array|null  $extra             扩展配置
 * @property bool        $is_default        是否默认模板
 */
class NodeDeployTemplate extends Model
{
    protected $table = 'node_deploy_templates';

    protected $fillable = [
        'name', 'description',
        'node_type', 'node_type2', 'core_type', 'node_inout_type',
        'group_ids', 'route_ids', 'tags', 'show', 'rate',
        'tls', 'server_name', 'flow', 'network', 'network_settings',
        'cert_mode', 'cert_domain',
        'release_repo', 'script_repo', 'script_branch', 'github_token',
        'cipher', 'plugin', 'plugin_opts',
        'extra', 'is_default',
    ];

    protected $casts = [
        'group_ids'       => 'array',
        'route_ids'       => 'array',
        'tags'            => 'array',
        'network_settings'=> 'array',
        'extra'           => 'array',
        'show'            => 'boolean',
        'is_default'      => 'boolean',
        'core_type'       => 'integer',
        'tls'             => 'integer',
    ];

    /**
     * 将模板转换为 deployConfig 数组，用于 NodeDeployService::deploy()
     */
    public function toDeployConfig(): array
    {
        return array_filter([
            'node_type'        => $this->node_type,
            'node_type2'       => $this->node_type2,
            'core_type'        => $this->core_type,
            'node_inout_type'  => $this->node_inout_type,
            'group_ids'        => $this->group_ids,
            'route_ids'        => $this->route_ids,
            'tags'             => $this->tags,
            'show'             => $this->show,
            'rate'             => $this->rate,
            'tls'              => $this->tls,
            'server_name'      => $this->server_name,
            'flow'             => $this->flow,
            'network'          => $this->network,
            'network_settings' => $this->network_settings,
            'cert_mode'        => $this->cert_mode,
            'cert_domain'      => $this->cert_domain,
            'release_repo'     => $this->release_repo,
            'script_repo'      => $this->script_repo,
            'script_branch'    => $this->script_branch,
            'github_token'     => $this->github_token,
            'cipher'           => $this->cipher,
            'plugin'           => $this->plugin,
            'plugin_opts'      => $this->plugin_opts,
            // extra 中的字段展开合并
            ...($this->extra ?? []),
        ], fn($v) => $v !== null && $v !== '' && $v !== []);
    }
}
