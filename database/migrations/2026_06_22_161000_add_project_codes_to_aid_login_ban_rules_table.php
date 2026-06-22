<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add project code source list for AID login ban rules.
     */
    public function up(): void
    {
        if (!Schema::hasTable('aid_login_ban_rules')) {
            return;
        }

        Schema::table('aid_login_ban_rules', function (Blueprint $table): void {
            if (!Schema::hasColumn('aid_login_ban_rules', 'project_codes')) {
                $table->json('project_codes')
                    ->nullable()
                    ->after('package_names')
                    ->comment('Project codes used to expand matched package names');
            }
        });
    }

    /**
     * Remove project code source list from AID login ban rules.
     */
    public function down(): void
    {
        if (!Schema::hasTable('aid_login_ban_rules')) {
            return;
        }

        Schema::table('aid_login_ban_rules', function (Blueprint $table): void {
            if (Schema::hasColumn('aid_login_ban_rules', 'project_codes')) {
                $table->dropColumn('project_codes');
            }
        });
    }
};
