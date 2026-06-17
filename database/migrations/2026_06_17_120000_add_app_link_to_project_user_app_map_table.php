<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add app link column for project user app bindings.
     */
    public function up(): void
    {
        if (!Schema::hasTable('project_user_app_map')) {
            return;
        }

        Schema::table('project_user_app_map', function (Blueprint $table) {
            if (!Schema::hasColumn('project_user_app_map', 'app_link')) {
                $table->string('app_link', 500)
                    ->nullable()
                    ->after('app_id')
                    ->comment('App 跳转或下载链接');
            }
        });
    }

    /**
     * Remove app link column for project user app bindings.
     */
    public function down(): void
    {
        if (!Schema::hasTable('project_user_app_map')) {
            return;
        }

        Schema::table('project_user_app_map', function (Blueprint $table) {
            if (Schema::hasColumn('project_user_app_map', 'app_link')) {
                $table->dropColumn('app_link');
            }
        });
    }
};
