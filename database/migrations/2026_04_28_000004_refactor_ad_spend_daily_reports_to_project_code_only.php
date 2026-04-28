<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ad_spend_platform_daily_reports')) {
            return;
        }

        Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
            if (Schema::hasColumn('ad_spend_platform_daily_reports', 'project_id')) {
                $table->dropIndex('idx_aspdr_project_date');
                $table->dropUnique('uk_ad_spend_daily');
            }
        });

        Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
            if (Schema::hasColumn('ad_spend_platform_daily_reports', 'project_id')) {
                $table->dropColumn('project_id');
            }
        });

        Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
            $table->unique(
                ['platform_account_id', 'project_code', 'report_date', 'country'],
                'uk_ad_spend_daily'
            );
            $table->index(['project_code', 'report_date'], 'idx_aspdr_project_code_date');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ad_spend_platform_daily_reports')) {
            return;
        }

        Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
            if (Schema::hasColumn('ad_spend_platform_daily_reports', 'project_id')) {
                return;
            }

            $table->dropIndex('idx_aspdr_project_code_date');
            $table->dropUnique('uk_ad_spend_daily');
            $table->unsignedBigInteger('project_id')->nullable()->comment('项目ID');
        });

        if (Schema::hasTable('project_projects')) {
            DB::statement("UPDATE ad_spend_platform_daily_reports d JOIN project_projects p ON p.project_code = d.project_code SET d.project_id = p.id WHERE d.project_id IS NULL");
        }

        Schema::table('ad_spend_platform_daily_reports', function (Blueprint $table) {
            $table->index(['project_id', 'report_date'], 'idx_aspdr_project_date');
            $table->unique(
                ['platform_account_id', 'project_id', 'report_date', 'country'],
                'uk_ad_spend_daily'
            );
        });
    }
};
