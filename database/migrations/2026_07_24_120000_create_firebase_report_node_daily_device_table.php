<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firebase_report_node_daily_device', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('UTC+8 date');
            $table->string('app_id', 128)->default('')->comment('App ID');
            $table->string('platform', 32)->default('')->comment('Platform');
            $table->string('app_version', 64)->default('')->comment('App version');
            $table->string('device_id', 128)->default('')->comment('Device ID');
            $table->unsignedBigInteger('client_connect_count')->default(0)->comment('VPN session count');
            $table->unsignedBigInteger('success_count')->default(0)->comment('Successful session count');
            $table->unsignedBigInteger('fail_count')->default(0)->comment('Failed session count');
            $table->unsignedBigInteger('cancel_count')->default(0)->comment('Client cancelled session count');
            $table->unsignedBigInteger('ping_sample_count')->default(0)->comment('Successful probe latency sample count');
            $table->unsignedBigInteger('ping_total_ms')->default(0)->comment('Successful probe latency total milliseconds');
            $table->dateTime('recomputed_at')->comment('Last recomputed time');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['date', 'app_id', 'platform', 'app_version', 'device_id'], 'uq_fa_node_daily_device_dim');
            $table->index(['date', 'app_id'], 'idx_fa_node_daily_device_date_app');
            $table->index(['app_id', 'date'], 'idx_fa_node_daily_device_app_date');
            $table->index(['platform', 'date'], 'idx_fa_node_daily_device_platform_date');
            $table->index(['app_version', 'date'], 'idx_fa_node_daily_device_version_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firebase_report_node_daily_device');
    }
};
