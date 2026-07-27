<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_projects') || Schema::hasColumn('project_projects', 'owner_id')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_id')->nullable()->after('project_name')->comment('负责人用户ID');
            $table->index('owner_id', 'idx_pp_owner_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('project_projects') || !Schema::hasColumn('project_projects', 'owner_id')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            $table->dropIndex('idx_pp_owner_id');
            $table->dropColumn('owner_id');
        });
    }
};
