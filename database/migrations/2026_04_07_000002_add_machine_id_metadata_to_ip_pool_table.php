<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_ip_pool', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')->nullable()->after('ip')->comment('绑定的机器ID');
            $table->json('metadata')->nullable()->after('readme_url')->comment('扩展元数据');

            $table->index('machine_id');
        });
    }

    public function down(): void
    {
        Schema::table('v2_ip_pool', function (Blueprint $table) {
            $table->dropIndex(['machine_id']);
            $table->dropColumn(['machine_id', 'metadata']);
        });
    }
};
