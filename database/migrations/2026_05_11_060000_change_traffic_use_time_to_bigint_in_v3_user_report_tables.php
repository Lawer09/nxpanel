<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v3_user_report_user', function (Blueprint $table) {
            $table->unsignedBigInteger('traffic_use_time')->default(0)->change();
        });

        Schema::table('v3_user_report_node', function (Blueprint $table) {
            $table->unsignedBigInteger('traffic_use_time')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('v3_user_report_user', function (Blueprint $table) {
            $table->unsignedInteger('traffic_use_time')->default(0)->change();
        });

        Schema::table('v3_user_report_node', function (Blueprint $table) {
            $table->unsignedInteger('traffic_use_time')->default(0)->change();
        });
    }
};
