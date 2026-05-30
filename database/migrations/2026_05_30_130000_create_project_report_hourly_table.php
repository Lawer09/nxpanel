<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_report_hourly')) {
            return;
        }

        Schema::create('project_report_hourly', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('date')->comment('报表日期');
            $table->unsignedTinyInteger('hour')->comment('小时 0-23');
            $table->string('project_code', 100)->comment('项目代号');
            $table->string('country', 50)->default('XX')->comment('国家，空值归一为XX');

            $table->unsignedInteger('install_users')->default(0)->comment('安装数（全生命周期首次上报小时）');
            $table->unsignedInteger('hourly_dau_users')->default(0)->comment('小时活跃用户数');
            $table->unsignedInteger('daily_dau_users')->default(0)->comment('日活跃用户数');

            $table->decimal('ad_revenue', 20, 6)->default(0)->comment('按小时分摊后的广告收益');
            $table->decimal('ad_spend_cost', 20, 6)->default(0)->comment('按小时分摊后的广告花费');
            $table->decimal('ros', 20, 6)->nullable()->comment('收益转化指标');

            $table->timestamps();

            $table->unique(['date', 'hour', 'project_code', 'country'], 'uk_project_report_hourly_dim');
            $table->index(['date', 'project_code'], 'idx_project_report_hourly_date_project');
            $table->index(['date', 'country'], 'idx_project_report_hourly_date_country');
            $table->index(['project_code', 'date', 'hour'], 'idx_project_report_hourly_project_date_hour');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_report_hourly');
    }
};
