<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v3_app_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('应用名称');
            $table->string('app_id', 64)->unique()->comment('应用 ID');
            $table->string('app_token', 64)->comment('应用 Token');
            $table->string('app_secret', 64)->comment('应用 Secret');
            $table->string('description', 500)->nullable()->comment('描述信息');
            $table->boolean('is_enabled')->default(true)->comment('是否启用');
            $table->timestamps();

            $table->index('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_app_clients');
    }
};
