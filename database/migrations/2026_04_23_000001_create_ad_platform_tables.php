<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_platform_account')) {
            return;
        }

        Schema::create('ad_platform_account', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_platform', 32);
            $table->string('account_name', 128);
            $table->string('publisher_id', 64)->default('');
            $table->string('account_label', 128)->default('');
            $table->json('tags')->nullable();
            $table->string('auth_type', 32)->default('oauth');
            $table->json('credentials_json');
            $table->string('reporting_timezone', 64)->default('');
            $table->string('currency_code', 8)->default('');
            $table->string('status', 16)->default('enabled');
            $table->json('ext_json')->nullable();
            $table->string('assigned_server_id', 64)->default('');
            $table->string('backup_server_id', 64)->default('');
            $table->string('isolation_group', 64)->default('');
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['source_platform', 'account_name'], 'uk_ad_platform_account_source_name');
            $table->index(['source_platform', 'status'], 'idx_ad_platform_account_platform_status');
            $table->index(['assigned_server_id', 'source_platform', 'status'], 'idx_ad_platform_account_assigned_server');
        });

        Schema::create('ad_platform_app', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_platform', 32);
            $table->unsignedBigInteger('account_id');
            $table->string('provider_app_id', 128);
            $table->string('provider_app_name', 255)->default('');
            $table->string('device_platform', 32)->default('');
            $table->string('app_store_id', 255)->default('');
            $table->string('app_approval_state', 64)->default('');
            $table->json('raw_json')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['source_platform', 'account_id', 'provider_app_id'], 'uk_ad_platform_app_source_account_app');
            $table->index('account_id', 'idx_ad_platform_app_account');
            $table->foreign('account_id')->references('id')->on('ad_platform_account');
        });

        Schema::create('ad_platform_ad_unit', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_platform', 32);
            $table->unsignedBigInteger('account_id');
            $table->string('provider_app_id', 128);
            $table->string('provider_ad_unit_id', 128);
            $table->string('provider_ad_unit_name', 255)->default('');
            $table->string('ad_format', 64)->default('');
            $table->json('ad_types_json')->nullable();
            $table->json('raw_json')->nullable();
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['source_platform', 'account_id', 'provider_ad_unit_id'], 'uk_ad_platform_ad_unit_source_account_unit');
            $table->index(['account_id', 'provider_app_id'], 'idx_ad_platform_ad_unit_account_app');
            $table->foreign('account_id')->references('id')->on('ad_platform_account');
        });

        Schema::create('project_platform_app_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('project_id');
            $table->string('source_platform', 32);
            $table->unsignedBigInteger('account_id');
            $table->string('provider_app_id', 128);
            $table->string('status', 16)->default('enabled');
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['project_id', 'source_platform', 'account_id', 'provider_app_id'], 'uk_project_platform_app_map');
            $table->foreign('account_id')->references('id')->on('ad_platform_account');
        });

        Schema::create('ad_revenue_daily', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_platform', 32);
            $table->string('report_type', 32);
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->date('report_date');
            $table->string('provider_app_id', 128)->default('');
            $table->string('provider_ad_unit_id', 128)->default('');
            $table->string('country_code', 16)->default('');
            $table->string('device_platform', 32)->default('');
            $table->string('ad_format', 64)->default('');
            $table->string('provider_app_name', 255)->default('');
            $table->string('provider_ad_unit_name', 255)->default('');
            $table->string('ad_source_code', 64)->default('');
            $table->string('ad_source_name', 128)->default('');
            $table->bigInteger('ad_requests')->default(0);
            $table->bigInteger('matched_requests')->default(0);
            $table->decimal('match_rate', 12, 6)->nullable();
            $table->bigInteger('impressions')->default(0);
            $table->decimal('show_rate', 12, 6)->nullable();
            $table->bigInteger('clicks')->default(0);
            $table->decimal('ctr', 12, 6)->nullable();
            $table->bigInteger('estimated_earnings_micros')->default(0);
            $table->decimal('estimated_earnings', 20, 6)->default(0);
            $table->bigInteger('ecpm_micros')->default(0);
            $table->decimal('ecpm', 20, 6)->default(0);
            $table->string('currency_code', 8)->default('');
            $table->json('raw_header_json')->nullable();
            $table->json('raw_row_json')->nullable();
            $table->timestamp('sync_time', 3)->useCurrent();
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique([
                'source_platform', 'report_type', 'account_id', 'report_date',
                'provider_app_id', 'provider_ad_unit_id', 'country_code',
                'device_platform', 'ad_format',
            ], 'uk_ad_revenue_daily_dim');
            $table->index(['account_id', 'report_date'], 'idx_ad_revenue_daily_account_date');
            $table->index(['project_id', 'report_date'], 'idx_ad_revenue_daily_project_date');
            $table->foreign('account_id')->references('id')->on('ad_platform_account');
        });

        Schema::create('ad_revenue_hourly', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_platform', 32)->comment('骞垮憡骞冲彴锛屽綋鍓嶄负 admob');
            $table->string('report_type', 32)->comment('鎶ヨ〃绫诲瀷锛屽綋鍓嶄负 network');
            $table->unsignedBigInteger('account_id')->comment('骞冲彴璐﹀彿 ID锛屽叧鑱?ad_platform_account.id');
            $table->unsignedBigInteger('project_id')->nullable()->comment('搴熷純瀛楁锛屼笉鍙備笌椤圭洰鏄犲皠閫昏緫');
            $table->date('report_date')->comment('灏忔椂缁撴灉鎵€灞炶嚜鐒舵棩锛屾寜 Asia/Shanghai 褰掑睘');
            $table->dateTime('report_hour')->comment('Hour bucket start time');
            $table->string('provider_app_id', 128)->default('')->comment('搴旂敤 ID');
            $table->string('provider_ad_unit_id', 128)->default('')->comment('骞垮憡浣?ID');
            $table->string('country_code', 16)->default('')->comment('鍥藉/鍦板尯浠ｇ爜');
            $table->string('device_platform', 32)->default('')->comment('璁惧骞冲彴');
            $table->string('ad_format', 64)->default('')->comment('骞垮憡鏍煎紡');
            $table->string('provider_app_name', 255)->default('')->comment('搴旂敤鍚嶇О');
            $table->string('provider_ad_unit_name', 255)->default('')->comment('Ad unit name');
            $table->string('ad_source_code', 64)->default('')->comment('Ad source code');
            $table->string('ad_source_name', 128)->default('')->comment('Ad source name');
            $table->bigInteger('ad_requests')->default(0)->comment('Hourly ad requests');
            $table->bigInteger('matched_requests')->default(0)->comment('Hourly matched requests');
            $table->decimal('match_rate', 12, 6)->nullable()->comment('Hourly match rate');
            $table->bigInteger('impressions')->default(0)->comment('Hourly impressions');
            $table->decimal('show_rate', 12, 6)->nullable()->comment('Hourly show rate');
            $table->bigInteger('clicks')->default(0)->comment('Hourly clicks');
            $table->decimal('ctr', 12, 6)->nullable()->comment('Hourly CTR');
            $table->bigInteger('estimated_earnings_micros')->default(0)->comment('灏忔椂棰勪及鏀跺叆锛屽崟浣?micros');
            $table->decimal('estimated_earnings', 20, 6)->default(0)->comment('灏忔椂棰勪及鏀跺叆');
            $table->bigInteger('ecpm_micros')->nullable()->comment('灏忔椂 eCPM锛屽崟浣?micros');
            $table->decimal('ecpm', 20, 6)->nullable()->comment('灏忔椂 eCPM');
            $table->string('currency_code', 8)->default('')->comment('甯佺浠ｇ爜');
            $table->tinyInteger('is_anomaly')->default(0)->comment('鏄惁瀛樺湪寮傚父');
            $table->string('anomaly_reason', 64)->default('')->comment('寮傚父鍘熷洜');
            $table->dateTime('baseline_snapshot_time', 3)->nullable()->comment('鐢ㄤ簬宸垎鐨勪笂涓€灏忔椂蹇収鏃堕棿');
            $table->dateTime('current_snapshot_time', 3)->comment('褰撳墠灏忔椂蹇収鏃堕棿');
            $table->dateTime('created_at', 3)->useCurrent()->comment('鍒涘缓鏃堕棿');
            $table->dateTime('updated_at', 3)->useCurrent()->useCurrentOnUpdate()->comment('鏇存柊鏃堕棿');

            $table->unique([
                'source_platform',
                'report_type',
                'account_id',
                'report_hour',
                'provider_app_id',
                'provider_ad_unit_id',
                'country_code',
                'device_platform',
                'ad_format',
            ], 'uk_ad_revenue_hourly_dim');
            $table->index(['account_id', 'report_date', 'report_hour'], 'idx_ad_revenue_hourly_account_date');
            $table->index(['project_id', 'report_date', 'report_hour'], 'idx_ad_revenue_hourly_project_date');
            $table->foreign('account_id', 'fk_ad_revenue_hourly_account')->references('id')->on('ad_platform_account');
        });

        Schema::create('ad_sync_state', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_platform', 32);
            $table->unsignedBigInteger('account_id');
            $table->string('sync_scope', 32);
            $table->timestamp('last_success_at', 3)->nullable();
            $table->timestamp('last_started_at', 3)->nullable();
            $table->date('last_sync_date')->nullable();
            $table->string('status', 16)->default('idle');
            $table->text('last_error_message');
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['source_platform', 'account_id', 'sync_scope'], 'uk_ad_sync_state_scope');
            $table->foreign('account_id')->references('id')->on('ad_platform_account');
        });

        Schema::create('ad_sync_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_platform', 32);
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('sync_scope', 32);
            $table->json('request_summary')->nullable();
            $table->integer('row_count')->default(0);
            $table->string('status', 16);
            $table->text('error_message');
            $table->string('server_id', 64)->default('');
            $table->timestamp('started_at', 3)->useCurrent();
            $table->timestamp('ended_at', 3)->nullable();

            $table->index(['sync_scope', 'started_at'], 'idx_ad_sync_log_scope_started');
            $table->index(['account_id', 'started_at'], 'idx_ad_sync_log_account_started');
            $table->index(['server_id', 'started_at'], 'idx_ad_sync_log_server');
            $table->foreign('account_id')->references('id')->on('ad_platform_account');
        });

        Schema::create('sync_server', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('server_id', 64);
            $table->string('server_name', 128)->default('');
            $table->string('host_ip', 64)->default('');
            $table->string('status', 16)->default('online');
            $table->json('tags')->nullable();
            $table->json('capabilities')->nullable();
            $table->timestamp('last_heartbeat_at', 3)->nullable();
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique('server_id', 'uk_sync_server_server_id');
            $table->index('status', 'idx_sync_server_status');
        });
    }

    /**
     * 鍥炴粴骞垮憡骞冲彴鐩稿叧琛ㄣ€?     */
    public function down(): void
    {
        Schema::dropIfExists('ad_sync_log');
        Schema::dropIfExists('ad_sync_state');
        Schema::dropIfExists('ad_revenue_hourly');
        Schema::dropIfExists('ad_revenue_daily');
        Schema::dropIfExists('project_platform_app_map');
        Schema::dropIfExists('ad_platform_ad_unit');
        Schema::dropIfExists('ad_platform_app');
        Schema::dropIfExists('sync_server');
        Schema::dropIfExists('ad_platform_account');
    }
};
