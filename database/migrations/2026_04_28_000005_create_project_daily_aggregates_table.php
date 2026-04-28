<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_daily_aggregates')) {
            return;
        }

        Schema::create('project_daily_aggregates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('report_date')->comment('报表日期');
            $table->string('project_code', 100)->comment('项目代号');
            $table->string('ad_country', 50)->default('')->comment('广告国家');

            $table->unsignedInteger('report_new_users')->default(0)->comment('上报新增用户');
            $table->unsignedInteger('dau_users')->default(0)->comment('日活跃');
            $table->unsignedInteger('register_new_users')->default(0)->comment('用户注册新增');

            $table->decimal('revenue', 20, 6)->default(0)->comment('预估收益');
            $table->bigInteger('ad_requests')->default(0)->comment('请求数');
            $table->bigInteger('matched_requests')->default(0)->comment('匹配数');
            $table->bigInteger('impressions')->default(0)->comment('展示量');
            $table->bigInteger('clicks')->default(0)->comment('点击量');
            $table->decimal('ecpm', 20, 6)->nullable()->comment('eCPM');
            $table->decimal('ctr', 12, 6)->nullable()->comment('CTR');
            $table->decimal('match_rate', 12, 6)->nullable()->comment('匹配率');
            $table->decimal('show_rate', 12, 6)->nullable()->comment('展示率');

            $table->decimal('ad_spend_cost', 20, 6)->default(0)->comment('广告投放成本');
            $table->decimal('traffic_usage_gb', 20, 6)->default(0)->comment('代理流量用量GB');
            $table->decimal('traffic_cost', 20, 6)->default(0)->comment('代理流量成本');
            $table->decimal('gross_profit', 20, 6)->default(0)->comment('毛利');
            $table->decimal('roi', 20, 6)->nullable()->comment('ROI');
            $table->decimal('cpi', 20, 6)->nullable()->comment('CPI');
            $table->decimal('fb_ecpm', 20, 6)->nullable()->comment('eCPM(来自FB数据)');

            $table->timestamps();

            $table->unique(['report_date', 'project_code', 'ad_country'], 'uk_project_daily_agg');
            $table->index(['report_date', 'project_code'], 'idx_project_daily_agg_date_project');
            $table->index(['report_date', 'ad_country'], 'idx_project_daily_agg_date_country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_daily_aggregates');
    }
};
