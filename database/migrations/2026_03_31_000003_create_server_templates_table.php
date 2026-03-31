<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('v2_server_template', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('模板名称');
            $table->string('description', 500)->nullable()->comment('备注');
            $table->boolean('is_default')->default(false)->comment('是否默认模板');

            // ── 基础配置 ─────────────────────────────────────────────────────
            $table->string('type', 30)->comment('协议类型: vless/vmess/trojan/...');
            $table->string('host', 255)->nullable()->comment('节点地址');
            $table->string('port', 11)->nullable()->comment('连接端口');
            $table->string('server_port', 11)->nullable()->comment('后端服务端口');
            $table->decimal('rate', 5, 2)->nullable()->default(1)->comment('倍率');
            $table->boolean('show')->nullable()->default(false)->comment('是否展示');
            $table->string('code', 100)->nullable()->comment('节点标识码');
            $table->string('spectific_key', 255)->nullable()->comment('特定密钥');

            // ── 关联 ──────────────────────────────────────────────────────────
            $table->json('group_ids')->nullable()->comment('用户组ID列表');
            $table->json('route_ids')->nullable()->comment('路由规则ID列表');
            $table->json('tags')->nullable()->comment('标签列表');
            $table->json('excludes')->nullable()->comment('排除节点');
            $table->json('ips')->nullable()->comment('IP列表');
            $table->integer('parent_id')->nullable()->comment('父节点ID');

            // ── 协议配置 ─────────────────────────────────────────────────────
            $table->json('protocol_settings')->nullable()->comment('协议相关设置');

            // ── 倍率时间段 ────────────────────────────────────────────────────
            $table->boolean('rate_time_enable')->nullable()->default(false)->comment('是否启用分时倍率');
            $table->json('rate_time_ranges')->nullable()->comment('分时倍率时间段');

            // ── 高级配置 ─────────────────────────────────────────────────────
            $table->json('custom_outbounds')->nullable()->comment('自定义出站');
            $table->json('custom_routes')->nullable()->comment('自定义路由');
            $table->json('cert_config')->nullable()->comment('证书配置');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_server_template');
    }
};
