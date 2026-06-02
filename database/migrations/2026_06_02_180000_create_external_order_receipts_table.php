<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create external order receipt records for idempotent third-party callbacks.
     */
    public function up(): void
    {
        Schema::create('external_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->comment('Third-party provider code');
            $table->string('external_order_id', 64)->comment('Third-party order ID');
            $table->string('status', 20)->default('pending')->comment('pending/processed/failed');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('local_order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->string('period', 32)->nullable();
            $table->string('transaction_id', 128)->nullable();
            $table->json('payload')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique(['provider', 'external_order_id'], 'uk_external_order_provider_order');
            $table->index('status');
            $table->index('user_id');
            $table->index('local_order_id');
        });
    }

    /**
     * Drop external order receipt records.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_order_receipts');
    }
};
