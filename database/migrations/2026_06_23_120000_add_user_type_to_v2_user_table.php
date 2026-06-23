<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'user_type')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('user_type', 32)->nullable()->default('global')->after('register_metadata')->comment('User type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_user', 'user_type')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('user_type');
            });
        }
    }
};
