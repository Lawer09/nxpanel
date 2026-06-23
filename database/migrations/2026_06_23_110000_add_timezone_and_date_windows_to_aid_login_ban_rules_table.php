<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Add timezone and specific date windows to AID login ban rules.
     */
    public function up(): void
    {
        if (!Schema::hasTable('aid_login_ban_rules')) {
            return;
        }

        Schema::table('aid_login_ban_rules', function (Blueprint $table): void {
            if (!Schema::hasColumn('aid_login_ban_rules', 'timezone')) {
                $table->string('timezone', 64)
                    ->default('Asia/Shanghai')
                    ->after('enabled')
                    ->comment('Timezone used to evaluate rule time conditions');
            }

            if (!Schema::hasColumn('aid_login_ban_rules', 'date_windows')) {
                $table->json('date_windows')
                    ->nullable()
                    ->after('weekly_windows')
                    ->comment('Specific date active windows');
            }
        });
    }

    /**
     * Remove timezone and specific date windows from AID login ban rules.
     */
    public function down(): void
    {
        if (!Schema::hasTable('aid_login_ban_rules')) {
            return;
        }

        Schema::table('aid_login_ban_rules', function (Blueprint $table): void {
            if (Schema::hasColumn('aid_login_ban_rules', 'date_windows')) {
                $table->dropColumn('date_windows');
            }

            if (Schema::hasColumn('aid_login_ban_rules', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};
