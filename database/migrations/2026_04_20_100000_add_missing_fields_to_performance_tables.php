<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // v2_node_performance_aggregated: 补全 app_id、app_version，移除 client_city
        Schema::table('v2_node_performance_aggregated', function (Blueprint $table) {
            // 先删除包含 client_city 的旧唯一索引
            $table->dropUnique('uq_perf_agg_dimension');
        });

        Schema::table('v2_node_performance_aggregated', function (Blueprint $table) {
            $table->string('app_id', 255)->nullable()->comment('App包名')->after('client_isp');
            $table->string('app_version', 50)->nullable()->comment('App版本')->after('app_id');
            $table->dropColumn('client_city');
        });

        Schema::table('v2_node_performance_aggregated', function (Blueprint $table) {
            // 新唯一索引：去掉 client_city，加入 app_id、app_version
            $table->unique(
                ['date', 'hour', 'minute', 'node_id', 'client_country', 'platform', 'client_isp', 'app_id', 'app_version'],
                'uq_perf_agg_dimension'
            );
        });

        // v3_user_report_count: 补全 client_country、client_isp（不加 client_city）
        Schema::table('v3_user_report_count', function (Blueprint $table) {
            $table->string('client_country', 2)->nullable()->comment('客户端国家')->after('node_count');
            $table->string('client_isp', 255)->nullable()->comment('客户端ISP')->after('client_country');
        });
    }

    public function down(): void
    {
        Schema::table('v2_node_performance_aggregated', function (Blueprint $table) {
            $table->dropUnique('uq_perf_agg_dimension');
        });

        Schema::table('v2_node_performance_aggregated', function (Blueprint $table) {
            $table->dropColumn(['app_id', 'app_version']);
            $table->string('client_city', 100)->nullable()->comment('客户端城市')->after('client_country');
        });

        Schema::table('v2_node_performance_aggregated', function (Blueprint $table) {
            $table->unique(
                ['date', 'hour', 'minute', 'node_id', 'client_country', 'client_city', 'platform', 'client_isp'],
                'uq_perf_agg_dimension'
            );
        });

        Schema::table('v3_user_report_count', function (Blueprint $table) {
            $table->dropColumn(['client_country', 'client_isp']);
        });
    }
};
