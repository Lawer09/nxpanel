<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Change postback deduplication to package_name + clickid.
     */
    public function up(): void
    {
        Schema::table('postback_receipts', function (Blueprint $table) {
            $table->dropUnique('uk_postback_receipts_clickid');
            $table->unique(['package_name', 'clickid'], 'uk_postback_receipts_package_clickid');
        });
    }

    /**
     * Restore the original clickid-only unique index.
     */
    public function down(): void
    {
        Schema::table('postback_receipts', function (Blueprint $table) {
            $table->dropUnique('uk_postback_receipts_package_clickid');
            $table->unique('clickid', 'uk_postback_receipts_clickid');
        });
    }
};
