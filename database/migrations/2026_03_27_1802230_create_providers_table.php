<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Provider (服务商) 表
        Schema::create('v2_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('提供商名称');
            $table->text('description')->nullable()->comment('描述');
            $table->string('website')->nullable()->comment('官网地址');
            $table->string('email')->nullable()->comment('联系邮箱');
            $table->string('phone')->nullable()->comment('联系电话');
            $table->string('country', 2)->nullable()->comment('国家代码');
            $table->string('type', 50)->nullable()->comment('类型: ISP/CDN/主机商/其他');
            $table->unsignedBigInteger('asn_id')->nullable()->comment('关联ASN ID');
            $table->string('asn', 50)->nullable()->comment('ASN号');
            $table->unsignedTinyInteger('reliability')->default(100)->comment('可靠性(0-100)');
            $table->unsignedTinyInteger('reputation')->default(50)->comment('声誉(0-100)');
            $table->unsignedTinyInteger('speed_level')->default(50)->comment('速度等级(0-100)');
            $table->unsignedTinyInteger('stability')->default(80)->comment('稳定性(0-100)');
            $table->boolean('is_active')->default(true)->comment('是否活跃');
            $table->json('regions')->nullable()->comment('覆盖地区 JSON数组');
            $table->json('services')->nullable()->comment('提供的服务 JSON数组');
            $table->json('metadata')->nullable()->comment('其他信息');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // 索引
            $table->index('name');
            $table->index('type');
            $table->index('country');
            $table->index('asn_id');
            $table->index('is_active');
            $table->index('reliability');
        });

        // 在表创建完成后添加外键约束
        Schema::table('v2_providers', function (Blueprint $table) {
            $table->foreign('asn_id')
                ->references('id')
                ->on('v2_asns')
                ->onDelete('set null')
                ->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_providers');
    }
};