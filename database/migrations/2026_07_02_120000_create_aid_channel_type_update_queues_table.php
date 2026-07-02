<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create queued channel_type updates for existing AID users.
     */
    public function up(): void
    {
        Schema::create('aid_channel_type_update_queues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->comment('AID user waiting for channel_type update');
            $table->string('channel_type', 32)->comment('Normalized channel_type to write back');
            $table->integer('last_login_at')->comment('AID login timestamp that produced this update');
            $table->unsignedInteger('attempts')->default(0)->comment('Failed flush attempts');
            $table->string('error_message', 500)->nullable()->comment('Last flush error');
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique('user_id', 'uk_aid_channel_type_update_queues_user_id');
            $table->index('updated_at', 'idx_aid_channel_type_update_queues_updated_at');
        });
    }

    /**
     * Drop queued channel_type updates.
     */
    public function down(): void
    {
        Schema::dropIfExists('aid_channel_type_update_queues');
    }
};
