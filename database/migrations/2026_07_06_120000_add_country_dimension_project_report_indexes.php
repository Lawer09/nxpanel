<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite indexes for country-dimension project daily report queries.
     */
    public function up(): void
    {
        if (Schema::hasTable('project_daily_aggregates')) {
            Schema::table('project_daily_aggregates', function (Blueprint $table) {
                $table->index(['report_date', 'country', 'project_code'], 'idx_pda_date_country_project');
            });
        }

        if (Schema::hasTable('ad_spend_platform_daily_reports')) {
            Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
                $table->index(['report_date', 'project_code', 'country'], 'idx_aspdr_date_project_country');
            });
        }
    }

    /**
     * Remove country-dimension project daily report indexes.
     */
    public function down(): void
    {
        if (Schema::hasTable('ad_spend_platform_daily_reports')) {
            Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
                $table->dropIndex('idx_aspdr_date_project_country');
            });
        }

        if (Schema::hasTable('project_daily_aggregates')) {
            Schema::table('project_daily_aggregates', function (Blueprint $table) {
                $table->dropIndex('idx_pda_date_country_project');
            });
        }
    }
};
