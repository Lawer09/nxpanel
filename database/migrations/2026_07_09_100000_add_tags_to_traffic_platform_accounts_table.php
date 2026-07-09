<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add lightweight tags to traffic platform accounts.
     */
    public function up(): void
    {
        if (!Schema::hasTable('traffic_platform_accounts')) {
            return;
        }

        if (Schema::hasColumn('traffic_platform_accounts', 'tags')) {
            return;
        }

        Schema::table('traffic_platform_accounts', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('balance')->comment('Account tags');
        });
    }

    /**
     * Remove traffic platform account tags.
     */
    public function down(): void
    {
        if (!Schema::hasTable('traffic_platform_accounts')) {
            return;
        }

        if (!Schema::hasColumn('traffic_platform_accounts', 'tags')) {
            return;
        }

        Schema::table('traffic_platform_accounts', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
