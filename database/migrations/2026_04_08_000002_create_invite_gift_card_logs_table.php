<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_invite_gift_card_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id')->comment('规则ID');
            $table->string('trigger_type', 20)->comment('触发类型: register/order_paid');
            $table->unsignedBigInteger('trigger_user_id')->comment('触发用户ID(注册/消费的用户)');
            $table->unsignedBigInteger('recipient_user_id')->comment('接收用户ID(邀请人或被邀请人)');
            $table->unsignedBigInteger('code_id')->comment('生成的兑换码ID');
            $table->unsignedBigInteger('order_id')->nullable()->comment('关联订单ID');
            $table->boolean('auto_redeemed')->default(false)->comment('是否已自动兑换');
            $table->json('metadata')->nullable()->comment('额外数据');
            $table->integer('created_at')->comment('创建时间');

            $table->index('rule_id');
            $table->index('trigger_user_id');
            $table->index('recipient_user_id');
            $table->index('code_id');
            $table->index('order_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_invite_gift_card_logs');
    }
};
