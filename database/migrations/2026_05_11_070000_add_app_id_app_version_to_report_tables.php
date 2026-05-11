<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v3_user_report_node', function (Blueprint $table) {
            $table->dropUnique('uq_ur_node_sum_dim');
            $table->string('app_id', 255)->default('')->after('probe_stage');
            $table->string('app_version', 50)->default('')->after('app_id');
            $table->unique(['date', 'hour', 'node_id', 'node_host', 'node_type', 'probe_stage', 'app_id', 'app_version'], 'uq_ur_node_sum_dim');
        });

        Schema::table('v3_node_server_report_node', function (Blueprint $table) {
            $table->dropUnique('uq_nsr_node_dim');
            $table->string('app_id', 255)->default('')->after('node_public_ip');
            $table->string('app_version', 50)->default('')->after('app_id');
            $table->unique(['date', 'hour', 'node_id', 'app_id', 'app_version'], 'uq_nsr_node_dim');
        });

        Schema::table('v3_report_node_hourly', function (Blueprint $table) {
            $table->dropUnique('uq_v3_report_node_hourly_dim');
            $table->string('app_id', 255)->default('')->after('probe_stage');
            $table->string('app_version', 50)->default('')->after('app_id');
            $table->unique(['date', 'hour', 'node_id', 'node_type', 'node_host', 'node_public_ip', 'probe_stage', 'app_id', 'app_version'], 'uq_v3_report_node_hourly_dim');
        });
    }

    public function down(): void
    {
        Schema::table('v3_user_report_node', function (Blueprint $table) {
            $table->dropUnique('uq_ur_node_sum_dim');
            $table->dropColumn(['app_id', 'app_version']);
            $table->unique(['date', 'hour', 'node_id', 'node_host', 'node_type', 'probe_stage'], 'uq_ur_node_sum_dim');
        });

        Schema::table('v3_node_server_report_node', function (Blueprint $table) {
            $table->dropUnique('uq_nsr_node_dim');
            $table->dropColumn(['app_id', 'app_version']);
            $table->unique(['date', 'hour', 'node_id'], 'uq_nsr_node_dim');
        });

        Schema::table('v3_report_node_hourly', function (Blueprint $table) {
            $table->dropUnique('uq_v3_report_node_hourly_dim');
            $table->dropColumn(['app_id', 'app_version']);
            $table->unique(['date', 'hour', 'node_id', 'node_type', 'node_host', 'node_public_ip', 'probe_stage'], 'uq_v3_report_node_hourly_dim');
        });
    }
};
