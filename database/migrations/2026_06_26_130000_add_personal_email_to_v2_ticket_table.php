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
        Schema::table('v2_ticket', function (Blueprint $table) {
            $table->string('personal_email', 255)->nullable()->after('level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v2_ticket', function (Blueprint $table) {
            $table->dropColumn('personal_email');
        });
    }
};
