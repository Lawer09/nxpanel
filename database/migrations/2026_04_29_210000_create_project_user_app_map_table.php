<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_user_app_map')) {
            return;
        }

        Schema::create('project_user_app_map', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('project_code', 100)->comment('项目代号');
            $table->string('app_id', 255)->comment('用户注册metadata中的app_id');
            $table->tinyInteger('enabled')->default(1)->comment('是否启用');
            $table->string('remark', 255)->nullable()->comment('备注');
            $table->timestamps();

            $table->unique(['project_code', 'app_id'], 'uk_project_user_app');
            $table->index('project_code', 'idx_puam_project_code');
            $table->index('app_id', 'idx_puam_app_id');
            $table->index(['project_code', 'enabled'], 'idx_puam_project_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user_app_map');
    }
};
