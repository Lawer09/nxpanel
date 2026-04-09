<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_ip_pool', function (Blueprint $table) {
            $table->unsignedBigInteger('provider_id')->nullable()->after('readme_url')->comment('供应商ID');
            $table->index('provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('v2_ip_pool', function (Blueprint $table) {
            $table->dropIndex(['provider_id']);
            $table->dropColumn('provider_id');
        });
    }
};