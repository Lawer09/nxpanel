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

        if (!Schema::hasColumn('project_daily_aggregates', 'spend_country')) {
            Schema::table('project_daily_aggregates', function (Blueprint $table) {
                $table->string('spend_country', 50)->default('')->after('ad_country')->comment('投放国家');
                $table->index(['report_date', 'spend_country'], 'idx_project_daily_agg_date_spend_country');
            });
        }

        DB::table('project_daily_aggregates')
            ->where(function ($query) {
                $query->whereNull('spend_country')->orWhere('spend_country', '=', '');
            })
            ->update(['spend_country' => DB::raw('ad_country')]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('project_daily_aggregates')) {
            return;
        }

        if (!Schema::hasColumn('project_daily_aggregates', 'spend_country')) {
            return;
        }

        Schema::table('project_daily_aggregates', function (Blueprint $table) {
            $table->dropIndex('idx_project_daily_agg_date_spend_country');
            $table->dropColumn('spend_country');
        });
    }
};
