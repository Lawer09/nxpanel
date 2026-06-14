<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create blocked IP records collected from banned users.
     */
    public function up(): void
    {
        Schema::create('blocked_user_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->comment('Blocked client IP');
            $table->unsignedBigInteger('banned_user_id')->nullable()->comment('User that contributed this blocked IP');
            $table->unsignedBigInteger('operator_user_id')->nullable()->comment('Admin user who created the block');
            $table->string('reason', 500)->nullable()->comment('Block reason');
            $table->json('metadata')->nullable()->comment('Extra block context');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique('ip', 'uk_blocked_user_ips_ip');
            $table->index('banned_user_id');
            $table->index('operator_user_id');
        });
    }

    /**
     * Drop blocked IP records.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocked_user_ips');
    }
};
