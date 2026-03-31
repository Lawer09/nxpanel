<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('node_deploy_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('模板名称');
            $table->string('description')->nullable()->comment('模板描述');

            // ── 节点协议配置 ──────────────────────────────────────────────────
            $table->string('node_type', 20)->default('vless')
                  ->comment('协议类型: vless/vmess/trojan/shadowsocks/hysteria/tuic/anytls');
            $table->string('node_type2', 20)->nullable()
                  ->comment('子类型: reality（仅 vless 有效）');
            $table->tinyInteger('core_type')->default(2)
                  ->comment('核心类型: 1=xray 2=singbox 3=hysteria2');
            $table->string('node_inout_type', 10)->default('stand')
                  ->comment('节点进出方向: stand/in/out');

            // ── 服务端节点注册配置 ────────────────────────────────────────────
            $table->json('group_ids')->nullable()->comment('权限组ID列表');
            $table->json('route_ids')->nullable()->comment('路由ID列表');
            $table->json('tags')->nullable()->comment('节点标签');
            $table->boolean('show')->default(false)->comment('是否前台显示');
            $table->string('rate', 10)->default('1')->comment('倍率');

            // ── Reality / TLS 配置 ────────────────────────────────────────────
            $table->tinyInteger('tls')->default(2)
                  ->comment('TLS 模式: 0=无 1=TLS 2=Reality');
            $table->string('server_name')->nullable()
                  ->comment('Reality/TLS 伪装站点，如 www.apple.com');
            $table->string('flow', 50)->nullable()
                  ->comment('VLESS flow，如 xtls-rprx-vision');
            $table->string('network', 20)->default('tcp')
                  ->comment('传输网络: tcp/ws/grpc/...');
            $table->json('network_settings')->nullable()->comment('传输层设置');

            // ── 证书配置 ──────────────────────────────────────────────────────
            $table->string('cert_mode', 10)->default('none')
                  ->comment('证书模式: none/http/dns/self');
            $table->string('cert_domain')->nullable()->comment('证书域名');

            // ── GitHub/安装脚本配置 ───────────────────────────────────────────
            $table->string('release_repo')->nullable()->comment('Release 仓库');
            $table->string('script_repo')->nullable()->comment('脚本仓库');
            $table->string('script_branch', 50)->nullable()->comment('脚本分支');
            $table->string('github_token')->nullable()->comment('GitHub Token（加密存储）');

            // ── Shadowsocks 专用 ──────────────────────────────────────────────
            $table->string('cipher', 50)->nullable()->comment('SS 加密方法');
            $table->string('plugin', 50)->nullable()->comment('SS 插件');
            $table->string('plugin_opts')->nullable()->comment('SS 插件参数');

            // ── 扩展 ──────────────────────────────────────────────────────────
            $table->json('extra')->nullable()->comment('其他扩展配置 JSON');

            $table->boolean('is_default')->default(false)->comment('是否为默认模板');
            $table->timestamps();

            $table->index('node_type');
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_deploy_templates');
    }
};
