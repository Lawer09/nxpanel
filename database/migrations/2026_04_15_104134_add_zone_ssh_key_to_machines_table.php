<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->string('provider_zone_id')->nullable()->after('provider_nic_id')->comment('服务商侧的可用区 ID');
            $table->string('ssh_key_id')->nullable()->after('provider_zone_id')->comment('密钥id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    { 
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('provider_zone_id');
            $table->dropColumn('ssh_key_id');
        });
    }
};
