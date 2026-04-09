<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->string('provider_nic_id')->nullable()->after('provider_instance_id')
                ->comment('服务商侧的网卡 ID（如 Vultr nic UUID）');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('provider_nic_id');
        });
    }
};
