<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add a risk type to blocked registration IP records.
     */
    public function up(): void
    {
        Schema::table('blocked_user_ips', function (Blueprint $table) {
            $table->string('type', 20)
                ->default('normal')
                ->after('ip')
                ->comment('Blocked IP type: normal or dangerous');
            $table->index('type', 'idx_blocked_user_ips_type');
        });
    }

    /**
     * Remove the risk type from blocked registration IP records.
     */
    public function down(): void
    {
        Schema::table('blocked_user_ips', function (Blueprint $table) {
            $table->dropIndex('idx_blocked_user_ips_type');
            $table->dropColumn('type');
        });
    }
};
