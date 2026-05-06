<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_node_traffic_aggregated', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->default(0)->after('minute')->comment('用户ID');
            $table->index(['date', 'user_id'], 'idx_traffic_date_user');
        });
    }

    public function down(): void
    {
        Schema::table('v2_node_traffic_aggregated', function (Blueprint $table) {
            $table->dropIndex('idx_traffic_date_user');
            $table->dropColumn('user_id');
        });
    }
};
