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
        Schema::table('v2_server', function (Blueprint $table) {
            $table->unsignedBigInteger('rate_limit')
                ->default(0)
                ->comment('Rate limit in bytes per second, 0 means unlimited')
                ->after('rate');
            $table->unsignedInteger('device_limit')
                ->default(0)
                ->comment('Device limit, 0 means unlimited')
                ->after('online_limit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn('rate_limit');
            $table->dropColumn('device_limit');
        });
    }
};
