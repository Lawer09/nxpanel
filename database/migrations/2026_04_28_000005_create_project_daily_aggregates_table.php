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
            $table->string('country', 50)->default('')->comment('国家');

            # 来自用户表和用户上报统计表
            $table->unsignedInteger('dau_users')->default(0)->comment('活跃用户数');
            $table->unsignedInteger('new_users')->default(0)->comment('新增用户数');
            # 来自广告平台数据
            $table->decimal('ad_revenue', 20, 6)->default(0)->comment('预估收益');
            $table->bigInteger('ad_requests')->default(0)->comment('请求数');
            $table->bigInteger('ad_matched_requests')->default(0)->comment('匹配数');
            $table->bigInteger('ad_impressions')->default(0)->comment('展示量');
            $table->bigInteger('ad_clicks')->default(0)->comment('点击量');
            $table->decimal('ad_ecpm', 20, 6)->nullable()->comment('eCPM');
            $table->decimal('ad_ctr', 12, 6)->nullable()->comment('CTR');
            $table->decimal('ad_match_rate', 12, 6)->nullable()->comment('匹配率');
            $table->decimal('ad_show_rate', 12, 6)->nullable()->comment('展示率');
            # 来自投放平台数据
            $table->decimal('ad_spend_cost', 20, 6)->default(0)->comment('广告投放成本');
            $table->decimal('ad_spend_cpi', 20, 6)->nullable()->comment('CPI');
            $table->decimal('ad_spend_cpc', 20, 6)->nullable()->comment('CPC');
            $table->decimal('ad_spend_cpm', 20, 6)->nullable()->comment('CPM');
            # 来自代理流量数据
            $table->decimal('traffic_usage_mb', 20, 6)->default(0)->comment('代理流量用量MB');
            $table->decimal('traffic_cost', 20, 6)->default(0)->comment('代理流量成本');
            # 计算
            $table->decimal('profit', 20, 6)->default(0)->comment('毛利');
            $table->decimal('roi', 20, 6)->nullable()->comment('ROI');

            $table->timestamps();

            $table->unique(['report_date', 'project_code', 'country'], 'uk_project_daily_agg');
            $table->index(['report_date', 'project_code'], 'idx_project_daily_agg_date_project');
            $table->index(['report_date', 'country'], 'idx_project_daily_agg_date_country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_daily_aggregates');
    }
};
