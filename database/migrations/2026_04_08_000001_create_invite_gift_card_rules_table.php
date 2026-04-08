<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_invite_gift_card_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('规则名称');
            $table->string('trigger_type', 20)->comment('触发类型: register/order_paid');
            $table->unsignedBigInteger('template_id')->comment('礼品卡模板ID');
            $table->string('target', 20)->comment('发放对象: inviter/invitee/both');
            $table->boolean('auto_redeem')->default(true)->comment('是否自动兑换');
            $table->unsignedBigInteger('min_order_amount')->default(0)->comment('最低订单金额(分)');
            $table->unsignedTinyInteger('order_type')->nullable()->comment('订单类型: 1新购/2续费/3升级');
            $table->unsignedInteger('max_issue_per_user')->default(0)->comment('每个邀请人最多发放次数(0不限)');
            $table->unsignedInteger('expires_hours')->nullable()->comment('兑换码有效期(小时)');
            $table->boolean('status')->default(true)->comment('状态: 0禁用/1启用');
            $table->unsignedInteger('sort')->default(0)->comment('排序');
            $table->text('description')->nullable()->comment('规则描述');
            $table->integer('created_at')->comment('创建时间');
            $table->integer('updated_at')->comment('更新时间');

            $table->index('trigger_type');
            $table->index('status');
            $table->index('template_id');
            $table->index('sort');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_invite_gift_card_rules');
    }
};
