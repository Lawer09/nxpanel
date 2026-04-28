<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('traffic_platform_platforms')) return;

        // 1. 三方流量平台配置表
        Schema::create('traffic_platform_platforms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->unique()->comment('平台编码，例如 kkoip');
            $table->string('name', 100)->comment('平台名称');
            $table->string('base_url', 255)->nullable()->comment('平台API基础地址');
            $table->tinyInteger('enabled')->default(1)->comment('是否启用');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();
        });

        // 插入默认平台
        DB::table('traffic_platform_platforms')->insert([
            'code' => 'kkoip',
            'name' => 'KKOIP',
            'base_url' => 'https://www.kkoip.com',
            'enabled' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. 三方流量平台账号表
        Schema::create('traffic_platform_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_id')->comment('平台ID');
            $table->string('platform_code', 50)->comment('平台编码');
            $table->string('account_name', 100)->comment('账号名称');
            $table->string('external_account_id', 100)->nullable()->comment('三方平台账号ID');
            $table->json('credential_json')->comment('账号凭证JSON');
            $table->string('timezone', 64)->default('Asia/Shanghai')->comment('账号数据时区');
            $table->tinyInteger('enabled')->default(1)->comment('是否启用');
            $table->dateTime('last_sync_at')->nullable()->comment('最近同步时间');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();

            $table->index(['platform_code', 'enabled'], 'idx_tp_accounts_platform_enabled');
        });

        // 3. 三方流量原始数据表（Go 写入，PHP 排查用）
        Schema::create('traffic_platform_usage_raw', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('平台账号ID');
            $table->string('platform_code', 50)->comment('平台编码');
            $table->string('external_uid', 100)->nullable()->comment('三方子账号ID');
            $table->string('external_username', 100)->nullable()->comment('三方子账号名称');
            $table->string('usage_time', 50)->comment('三方返回的原始时间');
            $table->string('geo', 100)->nullable()->comment('地区编码');
            $table->string('region', 100)->nullable()->comment('区域名称');
            $table->string('raw_count', 50)->nullable()->comment('三方返回的原始流量值');
            $table->json('raw_data')->comment('完整原始响应数据');
            $table->dateTime('created_at')->useCurrent();

            $table->unique(
                ['platform_account_id', 'platform_code', 'external_uid', 'usage_time', 'geo', 'region'],
                'uk_raw_record'
            );
        });

        // 4. 三方平台小时流量事实表（核心报表表）
        Schema::create('traffic_platform_usage_stat', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('平台账号ID');
            $table->string('platform_code', 50)->comment('平台编码');
            $table->string('external_uid', 100)->nullable()->comment('三方子账号ID');
            $table->string('external_username', 100)->nullable()->comment('三方子账号名称');
            $table->dateTime('stat_hour')->comment('统计小时');
            $table->date('stat_date')->comment('统计日期');
            $table->string('geo', 100)->nullable()->comment('地区编码');
            $table->string('region', 100)->nullable()->comment('区域名称');
            $table->bigInteger('traffic_bytes')->default(0)->comment('该小时流量字节数');
            $table->decimal('traffic_mb', 20, 6)->default(0)->comment('该小时流量MB');
            $table->decimal('traffic_gb', 20, 6)->default(0)->comment('该小时流量GB');
            $table->string('stat_method', 30)->comment('统计方式：api_hour、diff_from_day');
            $table->tinyInteger('is_estimated')->default(0)->comment('是否推算数据');
            $table->unsignedBigInteger('source_raw_id')->nullable()->comment('关联原始数据ID');
            $table->timestamps();

            $table->unique(
                ['platform_account_id', 'platform_code', 'external_uid', 'stat_hour', 'geo', 'region'],
                'uk_usage_hourly'
            );
            $table->index('stat_hour', 'idx_stat_hour');
            $table->index('stat_date', 'idx_stat_date');
            $table->index(['platform_account_id', 'stat_hour'], 'idx_account_hour');
            $table->index(['platform_code', 'stat_hour'], 'idx_platform_hour');
        });

        // 5. 三方流量日累计快照表
        Schema::create('traffic_platform_daily_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('平台账号ID');
            $table->string('platform_code', 50)->comment('平台编码');
            $table->string('external_uid', 100)->nullable()->comment('三方子账号ID');
            $table->string('external_username', 100)->nullable()->comment('三方子账号名称');
            $table->date('stat_date')->comment('统计日期');
            $table->string('geo', 100)->nullable()->comment('地区编码');
            $table->string('region', 100)->nullable()->comment('区域名称');
            $table->bigInteger('total_bytes')->default(0)->comment('当天累计流量字节数');
            $table->decimal('total_gb', 20, 6)->default(0)->comment('当天累计流量GB');
            $table->dateTime('snapshot_time')->comment('快照采集时间');
            $table->dateTime('created_at')->useCurrent();

            $table->index(
                ['platform_account_id', 'platform_code', 'external_uid', 'stat_date', 'snapshot_time'],
                'idx_snapshot_lookup'
            );
        });

        // 6. 三方流量同步任务记录表
        Schema::create('traffic_platform_sync_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('平台账号ID');
            $table->string('platform_code', 50)->comment('平台编码');
            $table->string('sync_type', 50)->comment('同步类型：overview、detail');
            $table->string('sync_mode', 30)->default('')->comment('同步模式：api_hour、api_day、diff_from_day');
            $table->dateTime('start_time')->comment('同步数据开始时间');
            $table->dateTime('end_time')->comment('同步数据结束时间');
            $table->string('status', 20)->comment('状态：running、success、failed');
            $table->json('request_params')->nullable()->comment('请求参数');
            $table->json('response_summary')->nullable()->comment('响应摘要');
            $table->text('error_message')->nullable()->comment('错误信息');
            $table->timestamps();

            $table->index(['platform_account_id', 'status'], 'idx_tp_sync_account_status');
            $table->index(['platform_code', 'status'], 'idx_tp_sync_platform_status');
            $table->index(['start_time', 'end_time'], 'idx_tp_sync_time_range');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_platform_sync_jobs');
        Schema::dropIfExists('traffic_platform_daily_snapshots');
        Schema::dropIfExists('traffic_platform_usage_stat');
        Schema::dropIfExists('traffic_platform_usage_raw');
        Schema::dropIfExists('traffic_platform_accounts');
        Schema::dropIfExists('traffic_platform_platforms');
    }
};
