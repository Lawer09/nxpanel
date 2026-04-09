<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 给 machines 表增加 ip_id 字段
        Schema::table('machines', function (Blueprint $table) {
            $table->unsignedBigInteger('ip_id')
                ->nullable()
                ->comment('Bound IP from IP pool')
                ->after('ip_address');
            
            $table->foreign('ip_id')
                ->references('id')
                ->on('v2_ip_pool')
                ->onDelete('set null');
        });

        // 给 v2_ip_pool 表增加 bandwidth 字段
        Schema::table('v2_ip_pool', function (Blueprint $table) {
            $table->unsignedInteger('bandwidth')
                ->nullable()
                ->comment('Bandwidth in Mbps')
                ->after('ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropForeign(['ip_id']);
            $table->dropColumn('ip_id');
        });

        Schema::table('v2_ip_pool', function (Blueprint $table) {
            $table->dropColumn('bandwidth');
        });
    }
};