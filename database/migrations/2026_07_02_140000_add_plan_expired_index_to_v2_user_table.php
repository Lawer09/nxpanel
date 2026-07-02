<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'idx_v2_user_plan_expired';

    /**
     * Add an index for plan capacity checks used by /plan/fetch.
     */
    public function up(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }

        Schema::table('v2_user', function (Blueprint $table) {
            $table->index(['plan_id', 'expired_at'], self::INDEX_NAME);
        });
    }

    /**
     * Remove the plan capacity check index.
     */
    public function down(): void
    {
        if (!Schema::hasTable('v2_user')) {
            return;
        }

        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};
