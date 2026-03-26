<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->string('gpu_info')->nullable()->after('disk')->comment('GPU信息');
            $table->integer('bandwidth')->nullable()->after('gpu_info')->comment('带宽 Mbps');
            $table->integer('provider')->nullable()->after('bandwidth')->comment('供应商');
            $table->decimal('price', 8, 2)->nullable()->after('provider')->comment('价格');
            $table->integer('pay_mode')->nullable()->after('price')->comment('付费模式');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            //
            $table->dropColumn([
                'gpu_info',
                'bandwidth',
                'provider',
                'price',
                'pay_mode',
            ]);
        });
    }
};
