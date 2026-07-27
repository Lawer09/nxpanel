<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_user') || Schema::hasColumn('v2_user', 'nickname')) {
            return;
        }

        Schema::table('v2_user', function (Blueprint $table) {
            $table->string('nickname', 100)->nullable()->after('email')->comment('用户昵称');
            $table->index('nickname', 'idx_v2_user_nickname');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_user') || !Schema::hasColumn('v2_user', 'nickname')) {
            return;
        }

        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropIndex('idx_v2_user_nickname');
            $table->dropColumn('nickname');
        });
    }
};
