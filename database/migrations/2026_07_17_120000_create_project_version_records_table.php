<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create project version records for release history management.
     */
    public function up(): void
    {
        if (Schema::hasTable('project_version_records')) {
            return;
        }

        Schema::create('project_version_records', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('project_id')->comment('Project id');
            $table->string('project_code', 100)->comment('Project code snapshot');
            $table->string('version', 100)->comment('Version name or number');
            $table->longText('content')->comment('Version content');
            $table->dateTime('release_time')->comment('Release time');
            $table->string('remark', 255)->nullable()->comment('Remark');
            $table->timestamps();

            $table->index('project_id', 'idx_pvr_project_id');
            $table->index('project_code', 'idx_pvr_project_code');
            $table->index('release_time', 'idx_pvr_release_time');
        });
    }

    /**
     * Drop project version records.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_version_records');
    }
};
