<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_daily_aggregates')) {
            return;
        }

        Schema::table('project_daily_aggregates', function (Blueprint $table) {
            if (!Schema::hasColumn('project_daily_aggregates', 'report_new_users')) {
                $table->unsignedInteger('report_new_users')->default(0)->after('new_users')->comment('上报新增用户数');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('project_daily_aggregates')) {
            return;
        }

        Schema::table('project_daily_aggregates', function (Blueprint $table) {
            if (Schema::hasColumn('project_daily_aggregates', 'report_new_users')) {
                $table->dropColumn('report_new_users');
            }
        });
    }
};
