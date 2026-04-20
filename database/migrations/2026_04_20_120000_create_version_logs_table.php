<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v3_version_logs', function (Blueprint $table) {
            $table->id();
            $table->string('version', 50)->comment('版本号，如 1.2.0');
            $table->string('title', 255)->comment('版本标题');
            $table->text('description')->nullable()->comment('版本概述');
            $table->json('features')->nullable()->comment('新增功能列表 JSON 数组');
            $table->json('improvements')->nullable()->comment('优化改进列表 JSON 数组');
            $table->json('bugfixes')->nullable()->comment('修复问题列表 JSON 数组');
            $table->date('release_date')->comment('发布日期');
            $table->boolean('is_published')->default(false)->comment('是否发布（前端可见）');
            $table->unsignedInteger('sort_order')->default(0)->comment('排序（越大越靠前）');
            $table->timestamps();

            $table->unique('version');
            $table->index('is_published');
            $table->index('release_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_version_logs');
    }
};
