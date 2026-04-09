<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ip_machine', function (Blueprint $table) {
            if (!Schema::hasColumn('ip_machine', 'is_egress')) {
                $table->boolean('is_egress')->default(false)->comment('是否为出口IP')->after('is_primary');
                $table->index(['machine_id', 'is_egress']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ip_machine', function (Blueprint $table) {
            if (Schema::hasColumn('ip_machine', 'is_egress')) {
                $table->dropIndex(['machine_id', 'is_egress']);
                $table->dropColumn('is_egress');
            }
        });
    }
};
