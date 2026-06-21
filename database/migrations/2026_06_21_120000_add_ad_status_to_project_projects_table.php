<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add project ad delivery status for project management and report filtering.
     */
    public function up(): void
    {
        if (!Schema::hasTable('project_projects') || Schema::hasColumn('project_projects', 'ad_status')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            $table->string('ad_status', 50)->nullable()->after('status')->comment('投放状态');
            $table->index('ad_status', 'idx_pp_ad_status');
        });
    }

    /**
     * Remove project ad delivery status.
     */
    public function down(): void
    {
        if (!Schema::hasTable('project_projects') || !Schema::hasColumn('project_projects', 'ad_status')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            $table->dropIndex('idx_pp_ad_status');
            $table->dropColumn('ad_status');
        });
    }
};
