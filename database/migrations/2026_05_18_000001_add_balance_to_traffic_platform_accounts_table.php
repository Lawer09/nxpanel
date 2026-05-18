<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('traffic_platform_accounts')) {
            return;
        }

        if (Schema::hasColumn('traffic_platform_accounts', 'balance')) {
            return;
        }

        Schema::table('traffic_platform_accounts', function (Blueprint $table) {
            $table->integer('balance')->default(0)->comment('剩余可用流量,MB')->after('enabled');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('traffic_platform_accounts')) {
            return;
        }

        if (!Schema::hasColumn('traffic_platform_accounts', 'balance')) {
            return;
        }

        Schema::table('traffic_platform_accounts', function (Blueprint $table) {
            $table->dropColumn('balance');
        });
    }
};
