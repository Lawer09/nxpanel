<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->json('register_metadata')->nullable()->after('remarks')->comment('注册时客户端元数据');
        });
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn('register_metadata');
        });
    }
};
