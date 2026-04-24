<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_server', function (Blueprint $table) {
            $table->string('secret_key', 128)->default('')->after('host_ip');
            $table->unsignedSmallInteger('port')->default(8080)->after('secret_key');
        });
    }

    public function down(): void
    {
        Schema::table('sync_server', function (Blueprint $table) {
            $table->dropColumn(['secret_key', 'port']);
        });
    }
};
