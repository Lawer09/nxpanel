<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_daily_aggregates')) {
            return;
        }

        if (!Schema::hasColumn('project_daily_aggregates', 'user_country')) {
            Schema::table('project_daily_aggregates', function (Blueprint $table) {
                $table->string('user_country', 50)->default('OO')->after('ad_country')->comment('用户国家');
                $table->index(['report_date', 'user_country'], 'idx_project_daily_agg_date_user_country');
            });
        }

        DB::table('project_daily_aggregates')
            ->where(function ($query) {
                $query->whereNull('user_country')->orWhere('user_country', '=', '');
            })
            ->update(['user_country' => 'OO']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('project_daily_aggregates')) {
            return;
        }

        if (!Schema::hasColumn('project_daily_aggregates', 'user_country')) {
            return;
        }

        Schema::table('project_daily_aggregates', function (Blueprint $table) {
            $table->dropIndex('idx_project_daily_agg_date_user_country');
            $table->dropColumn('user_country');
        });
    }
};
