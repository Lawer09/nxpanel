<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_projects')) return;

        // 1. 项目管理表
        Schema::create('project_projects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('project_code', 100)->unique()->comment('项目代号');
            $table->string('project_name', 100)->comment('项目名称');
            $table->string('owner_name', 100)->nullable()->comment('所属人名称');
            $table->string('status', 30)->default('active')->comment('状态：active、inactive、archived');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();

            $table->index('owner_name', 'idx_pp_owner_name');
            $table->index('project_code', 'idx_pp_project_code');
            $table->index('status', 'idx_pp_status');
        });

        // 2. 项目与流量平台账号关联表
        Schema::create('project_traffic_platform_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('project_code', 100)->comment('项目代号');
            $table->unsignedBigInteger('traffic_platform_account_id')->comment('流量平台账号ID');
            $table->string('platform_code', 50)->comment('流量平台编码');
            $table->string('external_uid', 100)->nullable()->comment('三方子账号ID');
            $table->string('external_username', 100)->nullable()->comment('三方子账号名称');
            $table->string('bind_type', 30)->default('account')->comment('绑定类型：account、sub_account');
            $table->tinyInteger('enabled')->default(1)->comment('是否启用');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();

            $table->unique(
                ['project_id', 'traffic_platform_account_id', 'external_uid'],
                'uk_project_traffic_account'
            );
            $table->index('project_id', 'idx_ptpa_project_id');
            $table->index('traffic_platform_account_id', 'idx_ptpa_traffic_account_id');
            $table->index('platform_code', 'idx_ptpa_platform_code');
        });

        // 3. 项目与广告变现账号关联表
        Schema::create('project_ad_platform_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('project_code', 100)->comment('项目代号');
            $table->unsignedBigInteger('ad_platform_account_id')->comment('广告变现平台账号ID');
            $table->string('platform_code', 50)->comment('广告平台编码');
            $table->string('external_app_id', 100)->nullable()->comment('广告平台应用ID');
            $table->string('external_ad_unit_id', 100)->nullable()->comment('广告位ID');
            $table->string('bind_type', 30)->default('account')->comment('绑定类型：account、app、ad_unit');
            $table->tinyInteger('enabled')->default(1)->comment('是否启用');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();

            $table->unique(
                ['project_id', 'ad_platform_account_id', 'external_app_id', 'external_ad_unit_id'],
                'uk_project_ad_account'
            );
            $table->index('project_id', 'idx_papa_project_id');
            $table->index('ad_platform_account_id', 'idx_papa_ad_account_id');
            $table->index('platform_code', 'idx_papa_platform_code');
        });

        // 4. 投放平台账号配置表
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

        // 5. 投放消耗日报表
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

        // 6. 未匹配项目记录表
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

        // 7. 投放平台同步任务记录表
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

    public function down(): void
    {
        Schema::dropIfExists('ad_spend_platform_sync_jobs');
        Schema::dropIfExists('ad_spend_platform_unmatched_reports');
        Schema::dropIfExists('ad_spend_platform_daily_reports');
        Schema::dropIfExists('ad_spend_platform_accounts');
        Schema::dropIfExists('project_ad_platform_accounts');
        Schema::dropIfExists('project_traffic_platform_accounts');
        Schema::dropIfExists('project_projects');
    }
};
