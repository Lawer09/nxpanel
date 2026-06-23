<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('v2_user', 'menus')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->json('menus')->nullable()->after('user_type')->comment('User menus');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_user', 'menus')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('menus');
            });
        }
    }
};
