<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a display name for project version records.
     */
    public function up(): void
    {
        if (!Schema::hasTable('project_version_records') || Schema::hasColumn('project_version_records', 'version_name')) {
            return;
        }

        Schema::table('project_version_records', function (Blueprint $table): void {
            $table->string('version_name', 191)->nullable()->after('version')->comment('Version display name');
        });
    }

    /**
     * Remove the project version display name.
     */
    public function down(): void
    {
        if (!Schema::hasTable('project_version_records') || !Schema::hasColumn('project_version_records', 'version_name')) {
            return;
        }

        Schema::table('project_version_records', function (Blueprint $table): void {
            $table->dropColumn('version_name');
        });
    }
};
