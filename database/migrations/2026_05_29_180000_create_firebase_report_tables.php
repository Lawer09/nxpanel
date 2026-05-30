<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('firebase_device_first_seen', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 128)->comment('设备唯一标识');
            $table->unsignedBigInteger('first_event_time_ms')->comment('首次事件时间（毫秒）');
            $table->dateTime('first_event_at')->comment('首次事件时间（UTC+8）');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('device_id', 'uq_firebase_device_first_seen_device');
            $table->index('first_event_time_ms', 'idx_firebase_device_first_seen_event_ms');
        });

        Schema::create('firebase_report_user_summary', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->dateTime('time_bucket')->comment('UTC+8 小时桶开始时间');
            $table->string('app_id', 128)->default('')->comment('应用ID');
            $table->string('app_version', 64)->default('')->comment('应用版本');
            $table->string('platform', 32)->default('')->comment('平台');
            $table->string('country', 16)->default('')->comment('用户国家');
            $table->string('network_type', 32)->default('')->comment('网络类型');
            $table->unsignedBigInteger('new_user_count')->default(0)->comment('新增设备数（按device_id首见）');
            $table->unsignedBigInteger('active_device_count')->default(0)->comment('小时活跃设备数');
            $table->unsignedBigInteger('dau_device_count')->default(0)->comment('日活设备数');
            $table->unsignedBigInteger('event_count')->default(0)->comment('事件总数');
            $table->dateTime('recomputed_at')->comment('最近重算时间');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'hour', 'app_id', 'app_version', 'platform', 'country', 'network_type'], 'uq_firebase_report_user_summary_dim');
            $table->index(['date', 'hour'], 'idx_firebase_report_user_summary_date_hour');
            $table->index(['app_id', 'app_version', 'date'], 'idx_firebase_report_user_summary_app_date');
            $table->index(['country', 'date'], 'idx_firebase_report_user_summary_country_date');
        });

        Schema::create('firebase_report_node', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->dateTime('time_bucket')->comment('UTC+8 小时桶开始时间');
            $table->string('app_id', 128)->default('')->comment('应用ID');
            $table->string('app_version', 64)->default('')->comment('应用版本');
            $table->string('country', 16)->default('')->comment('用户国家');
            $table->string('node_id', 128)->default('')->comment('节点ID');
            $table->string('node_host', 255)->default('')->comment('节点Host');
            $table->string('node_name', 128)->default('')->comment('节点名称');
            $table->string('node_country', 16)->default('')->comment('节点国家');
            $table->string('node_region', 64)->default('')->comment('节点地区');
            $table->string('protocol', 64)->default('')->comment('连接协议');
            $table->unsignedBigInteger('total_count')->default(0)->comment('会话总数');
            $table->unsignedBigInteger('success_count')->default(0)->comment('成功数');
            $table->unsignedBigInteger('fail_count')->default(0)->comment('失败数');
            $table->decimal('success_rate', 7, 4)->default(0)->comment('成功率 0-1');
            $table->unsignedBigInteger('avg_connect_ms')->default(0)->comment('平均连接耗时ms');
            $table->unsignedBigInteger('max_connect_ms')->default(0)->comment('最大连接耗时ms');
            $table->dateTime('recomputed_at')->comment('最近重算时间');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'hour', 'app_id', 'app_version', 'country', 'node_id', 'node_host'], 'uq_firebase_report_node_dim');
            $table->index(['date', 'hour'], 'idx_firebase_report_node_date_hour');
            $table->index(['node_id', 'date'], 'idx_firebase_report_node_node_date');
            $table->index(['app_id', 'app_version', 'date'], 'idx_firebase_report_node_app_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firebase_report_node');
        Schema::dropIfExists('firebase_report_user_summary');
        Schema::dropIfExists('firebase_device_first_seen');
    }
};
