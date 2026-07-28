<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create daily currency-rate snapshots for deterministic report replay.
     */
    public function up(): void
    {
        Schema::create('currency_rates_daily', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date')->comment('汇率日期');
            $table->string('base_currency', 8)->default('USD')->comment('基准货币');
            $table->string('currency_code', 8)->comment('币种');
            $table->decimal('rate_to_usd', 20, 10)->comment('该币种换算到 USD 的汇率');
            $table->string('source', 64)->default('exchangerate.host')->comment('汇率来源');
            $table->timestamp('synced_at')->nullable()->comment('同步时间');
            $table->json('raw_payload')->nullable()->comment('原始同步响应');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['rate_date', 'currency_code'], 'uq_currency_rates_daily_date_code');
            $table->index(['currency_code', 'rate_date'], 'idx_currency_rates_daily_code_date');
        });
    }

    /**
     * Drop daily currency-rate snapshots.
     */
    public function down(): void
    {
        Schema::dropIfExists('currency_rates_daily');
    }
};
