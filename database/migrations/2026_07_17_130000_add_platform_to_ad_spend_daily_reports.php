<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add remote platform dimension to daily ad spend reports.
     */
    public function up(): void
    {
        if (!Schema::hasTable('ad_spend_platform_daily_reports')) {
            return;
        }

        if (!Schema::hasColumn('ad_spend_platform_daily_reports', 'platform')) {
            Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
                $table->string('platform', 50)->default('')->after('country')->comment('Remote report platform dimension');
            });
        }

        Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
            $table->dropUnique('uk_ad_spend_daily');
            $table->unique(
                ['platform_account_id', 'project_code', 'report_date', 'country', 'platform'],
                'uk_ad_spend_daily'
            );
            $table->index(['platform', 'report_date'], 'idx_aspdr_platform_report_date');
        });
    }

    /**
     * Remove remote platform dimension from daily ad spend reports.
     */
    public function down(): void
    {
        if (!Schema::hasTable('ad_spend_platform_daily_reports')) {
            return;
        }

        Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
            $table->dropIndex('idx_aspdr_platform_report_date');
            $table->dropUnique('uk_ad_spend_daily');
            $table->unique(
                ['platform_account_id', 'project_code', 'report_date', 'country'],
                'uk_ad_spend_daily'
            );
        });

        if (Schema::hasColumn('ad_spend_platform_daily_reports', 'platform')) {
            Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
                $table->dropColumn('platform');
            });
        }
    }
};
