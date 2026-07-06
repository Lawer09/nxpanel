<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create project application information records keyed by project code and app id.
     */
    public function up(): void
    {
        if (Schema::hasTable('project_app_infos')) {
            return;
        }

        Schema::create('project_app_infos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('project_code', 100)->comment('Project code');
            $table->string('app_id', 255)->comment('Application id');
            $table->string('app_name', 191)->nullable()->comment('Application name');
            $table->string('platform', 50)->nullable()->comment('Application platform');
            $table->unsignedBigInteger('download_count')->default(0)->comment('Application download count');
            $table->json('download_data')->nullable()->comment('Application download data');
            $table->string('icon_url', 255)->nullable()->comment('Application icon URL');
            $table->string('chart_url', 255)->nullable()->comment('Application chart image URL');
            $table->json('image_urls')->nullable()->comment('Additional application image URLs');
            $table->string('store_url', 255)->nullable()->comment('Application store URL');
            $table->tinyInteger('enabled')->default(1)->comment('Whether the app info is enabled');
            $table->string('remark', 255)->nullable()->comment('Remark');
            $table->timestamps();

            $table->unique(['project_code', 'app_id'], 'uk_project_app_info');
            $table->index('project_code', 'idx_pai_project_code');
            $table->index('app_id', 'idx_pai_app_id');
            $table->index('enabled', 'idx_pai_enabled');
        });
    }

    /**
     * Drop project application information records.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_app_infos');
    }
};
