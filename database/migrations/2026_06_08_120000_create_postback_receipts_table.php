<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create public postback receipt records for click attribution callbacks.
     */
    public function up(): void
    {
        Schema::create('postback_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('package_name', 128)->comment('Application package name');
            $table->string('clickid', 255)->comment('Third-party click ID');
            $table->string('deviceid', 255)->comment('Client device ID');
            $table->string('request_ip', 45)->nullable()->comment('Request source IP');
            $table->string('user_agent', 1024)->nullable()->comment('Request user agent');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique('clickid', 'uk_postback_receipts_clickid');
            $table->index('package_name');
        });
    }

    /**
     * Drop public postback receipt records.
     */
    public function down(): void
    {
        Schema::dropIfExists('postback_receipts');
    }
};
