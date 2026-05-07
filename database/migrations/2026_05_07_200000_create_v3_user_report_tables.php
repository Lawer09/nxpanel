<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v3_user_report_summary', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->string('app_id', 255)->default('')->comment('App包名');
            $table->string('app_version', 50)->default('')->comment('App版本');
            $table->string('country', 16)->default('')->comment('客户端国家');
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->unsignedInteger('report_count')->default(0)->comment('上报次数');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'hour', 'user_id', 'app_id', 'app_version', 'country'], 'uq_ur_sum_dim');
            $table->index(['user_id', 'date'], 'idx_ur_sum_user_date');
            $table->index(['date', 'hour'], 'idx_ur_sum_date_hour');
        });

        Schema::create('v3_user_report_node_summary', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->unsignedBigInteger('node_id')->default(0)->comment('节点ID');
            $table->string('node_host', 255)->default('')->comment('节点Host');
            $table->string('node_type', 32)->default('')->comment('节点协议类型');
            $table->string('probe_stage', 32)->default('')->comment('探测阶段');
            $table->decimal('avg_delay', 10, 2)->default(0)->comment('平均延迟ms');
            $table->decimal('traffic_usage', 14, 3)->default(0)->comment('流量MB');
            $table->unsignedInteger('traffic_use_time')->default(0)->comment('使用时长秒');
            $table->unsignedInteger('compute_count')->default(0)->comment('计算样本数');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'hour', 'node_id', 'node_host', 'node_type', 'probe_stage'], 'uq_ur_node_sum_dim');
            $table->index(['node_id', 'date', 'hour'], 'idx_ur_node_sum_node_date_hour');
            $table->index(['date', 'hour'], 'idx_ur_node_sum_date_hour');
        });

        Schema::create('v3_user_report_traffic', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->string('app_id', 255)->default('')->comment('App包名');
            $table->string('app_version', 50)->default('')->comment('App版本');
            $table->string('country', 16)->default('')->comment('客户端国家');
            $table->decimal('traffic_usage', 14, 3)->default(0)->comment('流量MB');
            $table->unsignedInteger('traffic_use_time')->default(0)->comment('使用时长秒');
            $table->unsignedInteger('compute_count')->default(0)->comment('计算样本数');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'hour', 'user_id', 'app_id', 'app_version', 'country'], 'uq_ur_traffic_dim');
            $table->index(['user_id', 'date'], 'idx_ur_traffic_user_date');
            $table->index(['date', 'hour'], 'idx_ur_traffic_date_hour');
        });

        Schema::create('v3_user_report_node_fail', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->unsignedBigInteger('report_at_ms')->default(0)->comment('上报时间毫秒');
            $table->unsignedBigInteger('user_id')->default(0)->comment('用户ID');
            $table->string('app_id', 255)->default('')->comment('App包名');
            $table->string('country', 16)->default('')->comment('客户端国家');
            $table->unsignedBigInteger('node_id')->default(0)->comment('节点ID');
            $table->string('node_host', 255)->default('')->comment('节点Host');
            $table->string('node_type', 32)->default('')->comment('节点协议类型');
            $table->string('probe_stage', 32)->default('')->comment('探测阶段');
            $table->string('error_code', 255)->default('')->comment('错误码/错误信息');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['date', 'hour'], 'idx_ur_fail_date_hour');
            $table->index(['node_id', 'date'], 'idx_ur_fail_node_date');
            $table->index(['error_code', 'date'], 'idx_ur_fail_err_date');
            $table->index(['report_at_ms'], 'idx_ur_fail_report_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_user_report_node_fail');
        Schema::dropIfExists('v3_user_report_traffic');
        Schema::dropIfExists('v3_user_report_node_summary');
        Schema::dropIfExists('v3_user_report_summary');
    }
};
