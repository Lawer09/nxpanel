<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add covering indexes for performance dashboard user statistics.
     */
    public function up(): void
    {
        Schema::table('v3_user_report_count', function (Blueprint $table) {
            $table->index(['date', 'user_id'], 'idx_urc_date_user');
            $table->index(['date', 'hour', 'user_id'], 'idx_urc_date_hour_user');
            $table->index(['app_id', 'date', 'hour', 'user_id'], 'idx_urc_app_date_hour_user');
            $table->index(['platform', 'date', 'hour', 'user_id'], 'idx_urc_platform_date_hour_user');
            $table->index(['app_id', 'platform', 'date', 'hour', 'user_id'], 'idx_urc_app_platform_date_hour_user');
            $table->index(['user_id', 'date', 'hour'], 'idx_urc_user_date_hour');
        });
    }

    /**
     * Remove performance dashboard indexes.
     */
    public function down(): void
    {
        Schema::table('v3_user_report_count', function (Blueprint $table) {
            $table->dropIndex('idx_urc_date_user');
            $table->dropIndex('idx_urc_date_hour_user');
            $table->dropIndex('idx_urc_app_date_hour_user');
            $table->dropIndex('idx_urc_platform_date_hour_user');
            $table->dropIndex('idx_urc_app_platform_date_hour_user');
            $table->dropIndex('idx_urc_user_date_hour');
        });
    }
};
