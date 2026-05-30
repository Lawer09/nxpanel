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
            if (!Schema::hasColumn('project_daily_aggregates', 'fb_new_users')) {
                $table->unsignedInteger('fb_new_users')->default(0)->after('report_new_users')->comment('Firebase新增用户数');
            }
            if (!Schema::hasColumn('project_daily_aggregates', 'fb_dau_users')) {
                $table->unsignedInteger('fb_dau_users')->default(0)->after('fb_new_users')->comment('Firebase日活设备数');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('project_daily_aggregates')) {
            return;
        }

        Schema::table('project_daily_aggregates', function (Blueprint $table) {
            if (Schema::hasColumn('project_daily_aggregates', 'fb_dau_users')) {
                $table->dropColumn('fb_dau_users');
            }
            if (Schema::hasColumn('project_daily_aggregates', 'fb_new_users')) {
                $table->dropColumn('fb_new_users');
            }
        });
    }
};
