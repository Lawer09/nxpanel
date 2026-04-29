<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('project_platform_app_map')) {
            return;
        }

        Schema::table('project_platform_app_map', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
        });

        Schema::drop('project_platform_app_map');
    }

    public function down(): void
    {
        if (Schema::hasTable('project_platform_app_map')) {
            return;
        }

        Schema::create('project_platform_app_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('project_id');
            $table->string('source_platform', 32);
            $table->unsignedBigInteger('account_id');
            $table->string('provider_app_id', 128);
            $table->string('status', 16)->default('enabled');
            $table->timestamp('created_at', 3)->useCurrent();
            $table->timestamp('updated_at', 3)->useCurrent()->useCurrentOnUpdate();

            $table->unique(['project_id', 'source_platform', 'account_id', 'provider_app_id'], 'uk_project_platform_app_map');
            $table->foreign('account_id')->references('id')->on('ad_platform_account');
        });
    }
};
