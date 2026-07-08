<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create allowed IP records used by the IP access policy.
     */
    public function up(): void
    {
        Schema::create('allowed_user_ips', function (Blueprint $table): void {
            $table->id();
            $table->string('ip', 45)->comment('Allowed client IP');
            $table->unsignedBigInteger('operator_user_id')->nullable()->comment('User who created or updated the record');
            $table->string('reason', 500)->nullable()->comment('Allow reason');
            $table->json('metadata')->nullable()->comment('Extra allow context');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique('ip', 'uk_allowed_user_ips_ip');
            $table->index('operator_user_id');
        });
    }

    /**
     * Drop allowed IP records.
     */
    public function down(): void
    {
        Schema::dropIfExists('allowed_user_ips');
    }
};
