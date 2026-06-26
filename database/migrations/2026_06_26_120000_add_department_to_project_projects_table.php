<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the project department field used by project create/update APIs.
     */
    public function up(): void
    {
        if (!Schema::hasTable('project_projects') || Schema::hasColumn('project_projects', 'department')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            $table->string('department', 100)->nullable()->after('owner_name')->comment('Project department');
        });
    }

    /**
     * Remove the project department field.
     */
    public function down(): void
    {
        if (!Schema::hasTable('project_projects') || !Schema::hasColumn('project_projects', 'department')) {
            return;
        }

        Schema::table('project_projects', function (Blueprint $table) {
            $table->dropColumn('department');
        });
    }
};
