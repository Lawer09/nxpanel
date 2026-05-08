<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v3_node_server_report_node', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->unsignedBigInteger('node_id')->comment('节点ID');
            $table->string('node_type', 32)->default('')->comment('节点类型');
            $table->string('node_host', 255)->default('')->comment('节点Host');
            $table->string('node_public_ip', 64)->default('')->comment('节点公网IP');
            $table->unsignedBigInteger('traffic_upload')->default(0)->comment('上传流量 bytes');
            $table->unsignedBigInteger('traffic_download')->default(0)->comment('下载流量 bytes');
            $table->decimal('avg_cpu_usage', 12, 6)->default(0)->comment('平均CPU占用');
            $table->decimal('avg_mem_usage', 12, 6)->default(0)->comment('平均内存占用百分比');
            $table->decimal('max_cpu_usage', 12, 6)->default(0)->comment('最大CPU占用');
            $table->decimal('max_mem_usage', 12, 6)->default(0)->comment('最大内存占用百分比');
            $table->decimal('avg_disk_usage', 12, 6)->default(0)->comment('平均磁盘占用百分比');
            $table->decimal('avg_inbound_speed', 16, 6)->default(0)->comment('平均入站速率');
            $table->decimal('avg_outbound_speed', 16, 6)->default(0)->comment('平均出站速率');
            $table->decimal('max_inbound_speed', 16, 6)->default(0)->comment('最大入站速率');
            $table->decimal('max_outbound_speed', 16, 6)->default(0)->comment('最大出站速率');
            $table->decimal('avg_tcp_connections', 16, 6)->default(0)->comment('平均TCP连接数');
            $table->decimal('max_tcp_connections', 16, 6)->default(0)->comment('最大TCP连接数');
            $table->decimal('avg_alive_users', 16, 6)->default(0)->comment('平均活跃用户数');
            $table->decimal('max_alive_users', 16, 6)->default(0)->comment('最大活跃用户数');
            $table->unsignedInteger('compute_count')->default(0)->comment('样本数');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'hour', 'node_id'], 'uq_nsr_node_dim');
            $table->index(['date', 'hour'], 'idx_nsr_node_date_hour');
            $table->index(['node_id', 'date'], 'idx_nsr_node_node_date');
        });

        Schema::create('v3_node_server_report_user', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->unsignedBigInteger('node_id')->default(0)->comment('节点ID');
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->string('app_id', 255)->default('')->comment('App ID');
            $table->string('app_version', 50)->default('')->comment('App版本');
            $table->string('country', 16)->default('')->comment('国家');
            $table->unsignedBigInteger('traffic_upload')->default(0)->comment('上传流量 bytes');
            $table->unsignedBigInteger('traffic_download')->default(0)->comment('下载流量 bytes');
            $table->unsignedInteger('compute_count')->default(0)->comment('样本数');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'hour', 'node_id', 'user_id'], 'uq_nsr_user_dim');
            $table->index(['date', 'hour'], 'idx_nsr_user_date_hour');
            $table->index(['user_id', 'date'], 'idx_nsr_user_user_date');
            $table->index(['node_id', 'date'], 'idx_nsr_user_node_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_node_server_report_user');
        Schema::dropIfExists('v3_node_server_report_node');
    }
};
