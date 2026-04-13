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
        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('密钥名称');
            $table->string('tags')->nullable()->comment('标签');
            $table->unsignedBigInteger('provider_id')->nullable()->comment('云服务商ID');
            $table->string('provider_key_id')->nullable()->comment('云服务商密钥ID');
            $table->text('secret_key')->comment('密钥内容（加密存储）');
            $table->text('public_key')->nullable()->comment('公钥');
            $table->text('note')->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index('provider_id');
            $table->index('provider_key_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ssh_keys');
    }
};
