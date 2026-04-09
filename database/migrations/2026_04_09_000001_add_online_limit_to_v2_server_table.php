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
            $table->unsignedInteger('machine_id')
                ->nullable()
                ->comment('Bound machine ID')
                ->after('parent_id');
            $table->unsignedInteger('online_limit')
                ->nullable()
                ->comment('Online user limit (null = unlimited)')
                ->after('show');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn('machine_id');
            $table->dropColumn('online_limit');
        });
    }
};
