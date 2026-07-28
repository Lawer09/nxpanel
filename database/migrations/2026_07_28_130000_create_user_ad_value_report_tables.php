<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create hourly ad-value facts and per-app first-report cohorts.
     */
    public function up(): void
    {
        Schema::create('v3_user_ad_value_hourly', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 日期');
            $table->unsignedTinyInteger('hour')->comment('UTC+8 小时');
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->string('app_id', 255)->default('')->comment('App包名');
            $table->string('app_version', 50)->default('')->comment('App版本');
            $table->string('platform', 100)->default('')->comment('平台');
            $table->string('country', 16)->default('')->comment('客户端国家');
            $table->bigInteger('value_micros_usd')->default(0)->comment('广告价值，USD micros');
            $table->decimal('value_usd', 20, 6)->default(0)->comment('广告价值，USD');
            $table->unsignedBigInteger('ad_value_report_count')->default(0)->comment('广告价值上报条数');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'hour', 'user_id', 'app_id', 'app_version', 'platform', 'country'], 'uq_user_ad_value_hourly_dim');
            $table->index(['date', 'app_id', 'user_id'], 'idx_user_ad_value_date_app_user');
            $table->index(['app_id', 'date', 'hour'], 'idx_user_ad_value_app_date_hour');
            $table->index(['user_id', 'app_id', 'date'], 'idx_user_ad_value_user_app_date');
        });

        Schema::create('v3_user_app_first_report', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->string('app_id', 255)->default('')->comment('App包名');
            $table->date('first_report_date')->comment('首次上报 UTC+8 日期');
            $table->unsignedTinyInteger('first_report_hour')->default(0)->comment('首次上报 UTC+8 小时');
            $table->unsignedTinyInteger('first_report_minute')->default(0)->comment('首次上报 UTC+8 分钟');
            $table->unsignedBigInteger('first_report_at_ms')->default(0)->comment('首次上报时间毫秒');
            $table->string('platform', 100)->default('')->comment('首次上报平台');
            $table->string('app_version', 50)->default('')->comment('首次上报 App 版本');
            $table->string('country', 16)->default('')->comment('首次上报客户端国家');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['user_id', 'app_id'], 'uq_user_app_first_report');
            $table->index(['app_id', 'first_report_date', 'user_id'], 'idx_user_app_first_report_app_date');
        });
    }

    /**
     * Drop ad-value report tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('v3_user_app_first_report');
        Schema::dropIfExists('v3_user_ad_value_hourly');
    }
};
