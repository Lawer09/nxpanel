<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_platform_platforms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->unique()->comment('平台编码，例如 kkoip、ipweb');
            $table->string('name', 100)->comment('平台名称');
            $table->string('base_url', 255)->nullable()->comment('平台API基础地址');
            $table->tinyInteger('enabled')->default(1)->comment('是否启用');
            $table->dateTime('created_at')->comment('创建时间');
            $table->dateTime('updated_at')->comment('更新时间');
        });

        DB::table('traffic_platform_platforms')->updateOrInsert(
            ['code' => 'kkoip'],
            [
                'name' => 'KKOIP',
                'base_url' => 'https://www.kkoip.com',
                'enabled' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Schema::create('traffic_platform_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_id')->comment('平台ID');
            $table->string('platform_code', 50)->comment('平台编码');
            $table->string('account_name', 100)->comment('账号名称');
            $table->string('external_account_id', 100)->nullable()->comment('三方平台账号ID，例如 KKOIP accessid');
            $table->json('credential_json')->comment('账号凭证JSON，例如 accessid、secret、token');
            $table->string('timezone', 64)->default('Asia/Shanghai')->comment('账号数据时区');
            $table->tinyInteger('enabled')->default(1)->comment('是否启用');
            $table->dateTime('last_sync_at')->nullable()->comment('最近同步时间');
            $table->dateTime('created_at')->comment('创建时间');
            $table->dateTime('updated_at')->comment('更新时间');

            $table->index(['platform_code', 'enabled'], 'idx_platform_enabled');
        });

        Schema::create('traffic_platform_usage_stat', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('平台账号ID');
            $table->string('platform_code', 50)->comment('平台编码');
            $table->string('external_uid', 100)->default('')->comment('三方子账号ID');
            $table->string('external_username', 100)->nullable()->comment('三方子账号名称');
            $table->dateTime('stat_time')->comment('统计时间，精确到秒，例如 2026-04-28 10:23:59');
            $table->date('stat_date')->comment('统计日期');
            $table->unsignedTinyInteger('stat_hour')->comment('统计小时，0-23');
            $table->unsignedTinyInteger('stat_minute')->comment('统计分钟，0-59');
            $table->string('geo', 100)->default('')->comment('地区编码');
            $table->string('region', 100)->default('')->comment('区域名称');
            $table->bigInteger('traffic_bytes')->default(0)->comment('该条统计流量字节数');
            $table->decimal('traffic_mb', 20, 6)->default(0)->comment('该条统计流量MB');
            $table->dateTime('created_at')->comment('创建时间');
            $table->dateTime('updated_at')->comment('更新时间');

            $table->unique(
                ['platform_account_id', 'platform_code', 'external_uid', 'stat_time', 'geo', 'region'],
                'uk_usage_stat'
            );
            $table->index('stat_time', 'idx_stat_time');
            $table->index('stat_date', 'idx_stat_date');
            $table->index(['platform_account_id', 'stat_date'], 'idx_account_date');
            $table->index(['stat_date', 'stat_hour'], 'idx_date_hour');
        });

        Schema::create('traffic_platform_daily_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('平台账号ID');
            $table->string('platform_code', 50)->comment('平台编码');
            $table->string('external_uid', 100)->default('')->comment('三方子账号ID');
            $table->string('external_username', 100)->nullable()->comment('三方子账号名称');
            $table->date('stat_date')->comment('统计日期');
            $table->string('geo', 100)->default('')->comment('地区编码');
            $table->string('region', 100)->default('')->comment('区域名称');
            $table->bigInteger('total_bytes')->default(0)->comment('当天累计流量字节数');
            $table->decimal('total_mb', 20, 6)->default(0)->comment('当天累计流量MB');
            $table->dateTime('snapshot_time')->comment('快照采集时间');
            $table->dateTime('created_at')->comment('创建时间');

            $table->index(
                ['platform_account_id', 'platform_code', 'external_uid', 'stat_date', 'snapshot_time'],
                'idx_snapshot_lookup'
            );
        });

        Schema::create('traffic_platform_sync_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('平台账号ID');
            $table->string('platform_code', 50)->comment('平台编码');
            $table->string('sync_type', 50)->comment('同步类型：overview、detail');
            $table->dateTime('start_time')->comment('同步数据开始时间');
            $table->dateTime('end_time')->comment('同步数据结束时间');
            $table->string('status', 20)->comment('状态：running、success、failed');
            $table->json('request_params')->nullable()->comment('请求参数');
            $table->json('response_summary')->nullable()->comment('响应摘要');
            $table->text('error_message')->nullable()->comment('错误信息');
            $table->dateTime('created_at')->comment('创建时间');
            $table->dateTime('updated_at')->comment('更新时间');

            $table->index(['platform_account_id', 'status'], 'idx_account_status');
            $table->index(['platform_code', 'status'], 'idx_platform_status');
            $table->index(['start_time', 'end_time'], 'idx_time_range');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_platform_sync_jobs');
        Schema::dropIfExists('traffic_platform_daily_snapshots');
        Schema::dropIfExists('traffic_platform_usage_stat');
        Schema::dropIfExists('traffic_platform_accounts');
        Schema::dropIfExists('traffic_platform_platforms');
    }
};
