<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v3_report_user_hourly', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->unsignedBigInteger('user_id')->default(0)->comment('用户ID');
            $table->string('app_id', 255)->default('')->comment('App ID');
            $table->string('app_version', 50)->default('')->comment('App版本');
            $table->string('country', 16)->default('')->comment('国家');
            $table->decimal('traffic_usage', 20, 3)->default(0)->comment('用户侧流量KB(源MB*1024)');
            $table->unsignedBigInteger('traffic_use_time')->default(0)->comment('用户侧使用时长秒');
            $table->decimal('traffic_upload', 20, 3)->default(0)->comment('节点侧上传流量KB(源B/1024)');
            $table->decimal('traffic_download', 20, 3)->default(0)->comment('节点侧下载流量KB(源B/1024)');
            $table->unsignedBigInteger('report_count_user')->default(0)->comment('用户侧样本数');
            $table->unsignedBigInteger('report_count_node')->default(0)->comment('节点侧样本数');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'hour', 'user_id', 'app_id', 'app_version', 'country'], 'uq_v3_report_user_hourly_dim');
            $table->index(['date', 'hour'], 'idx_v3_report_user_hourly_date_hour');
            $table->index(['user_id', 'date'], 'idx_v3_report_user_hourly_user_date');
            $table->index(['app_id', 'app_version'], 'idx_v3_report_user_hourly_app');
            $table->index(['country', 'date'], 'idx_v3_report_user_hourly_country_date');
        });

        Schema::create('v3_report_node_hourly', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->unsignedBigInteger('node_id')->default(0)->comment('节点ID');
            $table->string('node_type', 32)->default('unknown')->comment('节点类型');
            $table->string('node_host', 255)->default('n.n.n.n')->comment('节点Host');
            $table->string('node_public_ip', 64)->default('0.0.0.0')->comment('节点公网IP');
            $table->string('probe_stage', 32)->default('post_connect_probe')->comment('探测阶段');
            $table->decimal('traffic_upload', 20, 3)->default(0)->comment('节点侧上传流量KB(源B/1024)');
            $table->decimal('traffic_download', 20, 3)->default(0)->comment('节点侧下载流量KB(源B/1024)');
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
            $table->decimal('avg_delay', 10, 2)->default(0)->comment('平均延迟ms');
            $table->decimal('traffic_usage', 20, 3)->default(0)->comment('用户侧流量KB(源MB*1024)');
            $table->unsignedBigInteger('traffic_use_time')->default(0)->comment('用户侧使用时长秒');
            $table->unsignedBigInteger('success_count')->default(0)->comment('成功数');
            $table->unsignedBigInteger('fail_count')->default(0)->comment('失败数');
            $table->decimal('success_rate', 7, 2)->default(0)->comment('成功率%');
            $table->unsignedBigInteger('report_count_node')->default(0)->comment('节点侧样本数');
            $table->unsignedBigInteger('report_count_user')->default(0)->comment('用户侧样本数');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'hour', 'node_id', 'node_type', 'node_host', 'node_public_ip', 'probe_stage'], 'uq_v3_report_node_hourly_dim');
            $table->index(['date', 'hour'], 'idx_v3_report_node_hourly_date_hour');
            $table->index(['node_id', 'date'], 'idx_v3_report_node_hourly_node_date');
            $table->index(['node_type', 'date'], 'idx_v3_report_node_hourly_type_date');
            $table->index(['probe_stage', 'date'], 'idx_v3_report_node_hourly_stage_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_report_node_hourly');
        Schema::dropIfExists('v3_report_user_hourly');
    }
};
