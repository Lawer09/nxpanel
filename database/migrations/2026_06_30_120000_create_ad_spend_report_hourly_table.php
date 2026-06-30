<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_spend_report_hourly', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('投放平台账号ID');
            $table->string('platform_code', 50)->comment('投放平台编码');
            $table->string('project_code', 100)->comment('项目代号');
            $table->date('report_date')->comment('报表日期');
            $table->unsignedTinyInteger('hour')->comment('小时，0-23');
            $table->string('country', 50)->default('XX')->comment('国家或地区；接口无国家维度时使用XX');
            $table->string('object_id', 100)->nullable()->comment('接口对象ID');
            $table->unsignedBigInteger('group_id')->nullable()->comment('接口组ID');
            $table->string('raw_group_name', 100)->comment('接口原始groupName');
            $table->string('group_key', 150)->comment('分组唯一键，优先groupName，回退groupId');
            $table->unsignedBigInteger('user_id')->nullable()->comment('接口用户ID');
            $table->unsignedBigInteger('agency_id')->nullable()->comment('接口代理商ID');
            $table->bigInteger('impressions')->default(0)->comment('展示数');
            $table->bigInteger('clicks')->default(0)->comment('点击数');
            $table->decimal('spend', 20, 6)->default(0)->comment('消耗金额');
            $table->decimal('ctr', 12, 6)->nullable()->comment('点击率');
            $table->decimal('cpm', 20, 6)->nullable()->comment('千次展示成本');
            $table->decimal('cpc', 20, 6)->nullable()->comment('点击成本');
            $table->decimal('roas', 20, 6)->nullable()->comment('广告支出回报率');
            $table->timestamps();

            $table->unique(
                ['platform_account_id', 'project_code', 'report_date', 'hour', 'country', 'group_key'],
                'uk_ad_spend_hourly'
            );
            $table->index(['project_code', 'report_date', 'hour'], 'idx_ash_project_date_hour');
            $table->index(['platform_code', 'report_date', 'hour'], 'idx_ash_platform_date_hour');
            $table->index('report_date', 'idx_ash_report_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend_report_hourly');
    }
};
