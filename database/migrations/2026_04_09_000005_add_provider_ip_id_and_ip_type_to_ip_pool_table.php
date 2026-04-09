<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_ip_pool', function (Blueprint $table) {
            $table->string('provider_ip_id')->nullable()->after('provider_id')->comment('云服务商侧IP ID');
            $table->string('ip_type', 32)->nullable()->after('provider_ip_id')->comment('IP类型');

            $table->index('provider_ip_id');
            $table->index('ip_type');
        });
    }

    public function down(): void
    {
        Schema::table('v2_ip_pool', function (Blueprint $table) {
            $table->dropIndex(['provider_ip_id']);
            $table->dropIndex(['ip_type']);
            $table->dropColumn(['provider_ip_id', 'ip_type']);
        });
    }
};