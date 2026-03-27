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
        // ASN (自治系统号) 表
        Schema::create('v2_asns', function (Blueprint $table) {
            $table->id();
            $table->string('asn', 50)->unique()->comment('ASN号 如: AS210644');
            $table->string('name')->comment('ASN名称');
            $table->text('description')->nullable()->comment('描述');
            $table->string('country', 2)->nullable()->comment('国家代码');
            $table->string('type', 50)->nullable()->comment('类型: ISP/CDN/企业');
            $table->boolean('is_datacenter')->default(false)->comment('是否数据中心');
            $table->unsignedTinyInteger('reliability')->default(100)->comment('可靠性(0-100)');
            $table->unsignedTinyInteger('reputation')->default(50)->comment('声誉(0-100)');
            $table->json('metadata')->nullable()->comment('其他信息');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // 索引
            $table->index('asn');
            $table->index('country');
            $table->index('type');
            $table->index('is_datacenter');
            $table->index('reliability');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asns');
    }
};
