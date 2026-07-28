<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_projects') || Schema::hasColumn('project_projects', 'owner_ids')) {
            return;
        }

        $afterColumn = Schema::hasColumn('project_projects', 'owner_id') ? 'owner_id' : 'owner_name';

        Schema::table('project_projects', function (Blueprint $table) use ($afterColumn) {
            $table->json('owner_ids')->nullable()->after($afterColumn)->comment('负责人用户ID列表');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('project_projects') || !Schema::hasColumn('project_projects', 'owner_ids')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            $table->dropColumn('owner_ids');
        });
    }
};
