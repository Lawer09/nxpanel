<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ad_spend_platform_accounts')) {
            Schema::create('ad_spend_platform_accounts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('platform_code', 50)->comment('投放平台编码');
                $table->string('account_name', 100)->comment('账号名称');
                $table->string('base_url', 255)->comment('接口基础地址');
                $table->string('username', 100)->comment('登录用户名');
                $table->string('password', 255)->comment('登录密码');
                $table->text('access_token')->nullable()->comment('登录后获取的 token');
                $table->dateTime('token_expired_at')->nullable()->comment('token 过期时间');
                $table->tinyInteger('enabled')->default(1)->comment('是否启用');
                $table->string('remark', 255)->nullable()->comment('备注');
                $table->dateTime('last_sync_at')->nullable()->comment('最近同步时间');
                $table->timestamps();

                $table->index(['platform_code', 'enabled'], 'idx_aspa_platform_enabled');
            });
        }

        if (!Schema::hasTable('ad_spend_platform_daily_reports')) {
            Schema::create('ad_spend_platform_daily_reports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('platform_account_id')->comment('投放平台账号ID');
                $table->string('platform_code', 50)->comment('投放平台编码');
                $table->string('project_code', 100)->comment('项目代号');
                $table->date('report_date')->comment('报表日期');
                $table->string('country', 50)->default('')->comment('国家或地区');
                $table->bigInteger('impressions')->default(0)->comment('展示数');
                $table->bigInteger('clicks')->default(0)->comment('点击数');
                $table->decimal('spend', 20, 6)->default(0)->comment('消耗金额');
                $table->decimal('ctr', 12, 6)->nullable()->comment('点击率');
                $table->decimal('cpm', 20, 6)->nullable()->comment('千次展示成本');
                $table->decimal('cpc', 20, 6)->nullable()->comment('点击成本');
                $table->string('raw_group_name', 100)->comment('接口原始 groupName');
                $table->timestamps();

                $table->unique(
                    ['platform_account_id', 'project_code', 'report_date', 'country'],
                    'uk_ad_spend_daily'
                );
                $table->index(['project_code', 'report_date'], 'idx_aspdr_project_code_date');
                $table->index(['platform_code', 'report_date'], 'idx_aspdr_platform_date');
                $table->index('report_date', 'idx_aspdr_report_date');
            });
        }

        if (!Schema::hasTable('ad_spend_platform_unmatched_reports')) {
            Schema::create('ad_spend_platform_unmatched_reports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('platform_account_id')->comment('投放平台账号ID');
                $table->string('platform_code', 50)->comment('投放平台编码');
                $table->string('raw_group_name', 100)->comment('接口返回 groupName');
                $table->date('report_date')->comment('报表日期');
                $table->string('country', 50)->default('')->comment('国家或地区');
                $table->bigInteger('impressions')->default(0)->comment('展示数');
                $table->bigInteger('clicks')->default(0)->comment('点击数');
                $table->decimal('spend', 20, 6)->default(0)->comment('消耗金额');
                $table->decimal('ctr', 12, 6)->nullable()->comment('点击率');
                $table->decimal('cpm', 20, 6)->nullable()->comment('千次展示成本');
                $table->decimal('cpc', 20, 6)->nullable()->comment('点击成本');
                $table->json('raw_data')->nullable()->comment('原始数据');
                $table->timestamps();

                $table->unique(
                    ['platform_account_id', 'raw_group_name', 'report_date', 'country'],
                    'uk_unmatched_daily'
                );
                $table->index('raw_group_name', 'idx_aspur_raw_group_name');
                $table->index('report_date', 'idx_aspur_report_date');
            });
        }

        if (!Schema::hasTable('ad_spend_platform_sync_jobs')) {
            Schema::create('ad_spend_platform_sync_jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('platform_account_id')->comment('投放平台账号ID');
                $table->string('platform_code', 50)->comment('投放平台编码');
                $table->date('start_date')->comment('同步开始日期');
                $table->date('end_date')->comment('同步结束日期');
                $table->string('status', 20)->comment('running、success、failed');
                $table->integer('total_records')->default(0)->comment('接口返回总记录数');
                $table->integer('matched_records')->default(0)->comment('成功匹配项目记录数');
                $table->integer('unmatched_records')->default(0)->comment('未匹配项目记录数');
                $table->json('request_params')->nullable()->comment('请求参数');
                $table->text('error_message')->nullable()->comment('错误信息');
                $table->timestamps();

                $table->index(['platform_account_id', 'status'], 'idx_aspsj_account_status');
                $table->index(['start_date', 'end_date'], 'idx_aspsj_date_range');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend_platform_sync_jobs');
        Schema::dropIfExists('ad_spend_platform_unmatched_reports');
        Schema::dropIfExists('ad_spend_platform_daily_reports');
        Schema::dropIfExists('ad_spend_platform_accounts');
    }
};
