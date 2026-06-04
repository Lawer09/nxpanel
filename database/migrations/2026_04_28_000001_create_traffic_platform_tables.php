<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_platform_platforms', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->unique()->comment('骞冲彴缂栫爜锛屼緥濡?kkoip銆乮pweb');
            $table->string('name', 100)->comment('骞冲彴鍚嶇О');
            $table->string('base_url', 255)->nullable()->comment('骞冲彴 API 鍩虹鍦板潃');
            $table->tinyInteger('enabled')->default(1)->comment('鏄惁鍚敤');
            $table->dateTime('created_at')->comment('鍒涘缓鏃堕棿');
            $table->dateTime('updated_at')->comment('鏇存柊鏃堕棿');
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
            $table->unsignedBigInteger('platform_id')->comment('骞冲彴 ID');
            $table->string('platform_code', 50)->comment('骞冲彴缂栫爜');
            $table->string('account_name', 100)->comment('璐﹀彿鍚嶇О');
            $table->string('external_account_id', 100)->nullable()->comment('绗笁鏂瑰钩鍙拌处鍙?ID');
            $table->json('credential_json')->comment('璐﹀彿鍑瘉 JSON');
            $table->string('timezone', 64)->default('Asia/Shanghai')->comment('璐﹀彿鏁版嵁鏃跺尯');
            $table->tinyInteger('enabled')->default(1)->comment('鏄惁鍚敤');
            $table->dateTime('last_sync_at')->nullable()->comment('Last sync time');
            $table->dateTime('created_at')->comment('鍒涘缓鏃堕棿');
            $table->dateTime('updated_at')->comment('鏇存柊鏃堕棿');

            $table->index(['platform_code', 'enabled'], 'idx_platform_enabled');
        });

        Schema::create('traffic_platform_usage_hourly', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('骞冲彴璐﹀彿 ID');
            $table->string('platform_code', 50)->comment('骞冲彴缂栫爜');
            $table->string('external_uid', 100)->default('')->comment('澶栭儴鐢ㄦ埛 ID');
            $table->string('external_username', 100)->default('')->comment('External username');
            $table->date('report_date')->comment('褰掑睘鏃ユ湡');
            $table->dateTime('report_hour')->comment('褰掑睘灏忔椂璧风偣');
            $table->string('geo', 100)->default('')->comment('鍦扮悊缂栫爜');
            $table->string('region', 100)->default('')->comment('鍖哄煙鍚嶇О');
            $table->bigInteger('traffic_bytes')->default(0)->comment('Hourly traffic bytes');
            $table->decimal('traffic_mb', 20, 6)->default(0)->comment('灏忔椂娴侀噺 MB');
            $table->dateTime('baseline_snapshot_time')->nullable()->comment('鍩虹嚎蹇収鏃堕棿');
            $table->dateTime('current_snapshot_time')->comment('褰撳墠蹇収鏃堕棿');
            $table->tinyInteger('is_anomaly')->default(0)->comment('鏄惁寮傚父');
            $table->string('anomaly_reason', 64)->default('')->comment('寮傚父鍘熷洜');
            $table->dateTime('created_at')->comment('鍒涘缓鏃堕棿');
            $table->dateTime('updated_at')->comment('鏇存柊鏃堕棿');

            $table->unique(
                ['platform_account_id', 'platform_code', 'external_uid', 'report_hour', 'geo', 'region'],
                'uk_usage_hourly'
            );
            $table->index('report_date', 'idx_hourly_report_date');
            $table->index('report_hour', 'idx_hourly_report_hour');
            $table->index(['platform_account_id', 'report_hour'], 'idx_hourly_account_hour');
        });

        Schema::create('traffic_platform_usage_daily', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('骞冲彴璐﹀彿 ID');
            $table->string('platform_code', 50)->comment('骞冲彴缂栫爜');
            $table->string('external_uid', 100)->default('')->comment('澶栭儴鐢ㄦ埛 ID');
            $table->string('external_username', 100)->default('')->comment('External username');
            $table->date('report_date')->comment('褰掑睘鏃ユ湡');
            $table->dateTime('snapshot_time')->comment('Latest snapshot time');
            $table->string('geo', 100)->default('')->comment('鍦扮悊缂栫爜');
            $table->string('region', 100)->default('')->comment('鍖哄煙鍚嶇О');
            $table->bigInteger('traffic_bytes_cum')->default(0)->comment('Daily cumulative traffic bytes');
            $table->decimal('traffic_mb_cum', 20, 6)->default(0)->comment('褰撳ぉ绱娴侀噺 MB');
            $table->dateTime('created_at')->comment('鍒涘缓鏃堕棿');
            $table->dateTime('updated_at')->comment('鏇存柊鏃堕棿');

            $table->unique(
                ['platform_account_id', 'platform_code', 'external_uid', 'report_date', 'geo', 'region'],
                'uk_usage_daily'
            );
            $table->index('report_date', 'idx_daily_report_date');
            $table->index('snapshot_time', 'idx_daily_snapshot_time');
            $table->index(['platform_account_id', 'report_date'], 'idx_daily_account_date');
        });

        Schema::create('traffic_platform_sync_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('platform_account_id')->comment('骞冲彴璐﹀彿 ID');
            $table->string('platform_code', 50)->comment('骞冲彴缂栫爜');
            $table->string('sync_type', 50)->comment('鍚屾绫诲瀷锛屼緥濡?overview銆乨etail');
            $table->dateTime('start_time')->comment('Sync data start time');
            $table->dateTime('end_time')->comment('鍚屾鏁版嵁缁撴潫鏃堕棿');
            $table->string('status', 20)->comment('鐘舵€侊細running銆乻uccess銆乫ailed');
            $table->json('request_params')->nullable()->comment('璇锋眰鍙傛暟');
            $table->json('response_summary')->nullable()->comment('鍝嶅簲鎽樿');
            $table->text('error_message')->nullable()->comment('閿欒淇℃伅');
            $table->dateTime('created_at')->comment('鍒涘缓鏃堕棿');
            $table->dateTime('updated_at')->comment('鏇存柊鏃堕棿');

            $table->index(['platform_account_id', 'status'], 'idx_account_status');
            $table->index(['platform_code', 'status'], 'idx_platform_status');
            $table->index(['start_time', 'end_time'], 'idx_time_range');
        });
    }

    /**
     * 鍥炴粴娴侀噺骞冲彴鐩稿叧琛ㄣ€?     */
    public function down(): void
    {
        Schema::dropIfExists('traffic_platform_sync_jobs');
        Schema::dropIfExists('traffic_platform_usage_daily');
        Schema::dropIfExists('traffic_platform_usage_hourly');
        Schema::dropIfExists('traffic_platform_accounts');
        Schema::dropIfExists('traffic_platform_platforms');
    }
};
