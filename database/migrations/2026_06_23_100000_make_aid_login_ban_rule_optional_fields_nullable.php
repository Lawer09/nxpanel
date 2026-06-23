<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Make optional AID login ban rule fields nullable for databases migrated before the rules were relaxed.
     */
    public function up(): void
    {
        if (!Schema::hasTable('aid_login_ban_rules')) {
            return;
        }

        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('ALTER TABLE `aid_login_ban_rules` MODIFY `cutoff_at` BIGINT NULL COMMENT "Rule effective cutoff timestamp"');
        DB::statement('ALTER TABLE `aid_login_ban_rules` MODIFY `weekly_windows` JSON NULL COMMENT "Active weekly windows"');
    }

    /**
     * Restore the previous non-null constraints for optional fields.
     */
    public function down(): void
    {
        if (!Schema::hasTable('aid_login_ban_rules')) {
            return;
        }

        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement('UPDATE `aid_login_ban_rules` SET `cutoff_at` = 0 WHERE `cutoff_at` IS NULL');
        DB::statement('UPDATE `aid_login_ban_rules` SET `weekly_windows` = JSON_ARRAY() WHERE `weekly_windows` IS NULL');
        DB::statement('ALTER TABLE `aid_login_ban_rules` MODIFY `cutoff_at` BIGINT NOT NULL COMMENT "Rule effective cutoff timestamp"');
        DB::statement('ALTER TABLE `aid_login_ban_rules` MODIFY `weekly_windows` JSON NOT NULL COMMENT "Active weekly windows"');
    }
};
