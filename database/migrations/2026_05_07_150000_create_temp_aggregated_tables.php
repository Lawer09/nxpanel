<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS v3_user_report_count_temp LIKE v3_user_report_count');
        DB::statement('CREATE TABLE IF NOT EXISTS v2_node_performance_aggregated_temp LIKE v2_node_performance_aggregated');
        DB::statement('CREATE TABLE IF NOT EXISTS v2_node_probe_aggregated_temp LIKE v2_node_probe_aggregated');
        DB::statement('CREATE TABLE IF NOT EXISTS v2_node_traffic_aggregated_temp LIKE v2_node_traffic_aggregated');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS v3_user_report_count_temp');
        DB::statement('DROP TABLE IF EXISTS v2_node_performance_aggregated_temp');
        DB::statement('DROP TABLE IF EXISTS v2_node_probe_aggregated_temp');
        DB::statement('DROP TABLE IF EXISTS v2_node_traffic_aggregated_temp');
    }
};
