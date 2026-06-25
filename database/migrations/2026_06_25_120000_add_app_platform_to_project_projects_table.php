<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add project application platform for project management and report filtering.
     */
    public function up(): void
    {
        if (!Schema::hasTable('project_projects') || Schema::hasColumn('project_projects', 'app_platform')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            $table->string('app_platform', 50)->nullable()->after('ad_status')->comment('应用平台');
            $table->index('app_platform', 'idx_pp_app_platform');
        });
    }

    /**
     * Remove project application platform.
     */
    public function down(): void
    {
        if (!Schema::hasTable('project_projects') || !Schema::hasColumn('project_projects', 'app_platform')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            $table->dropIndex('idx_pp_app_platform');
            $table->dropColumn('app_platform');
        });
    }
};
