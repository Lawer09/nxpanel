<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_performance_report', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->comment('用户ID');
            $table->unsignedInteger('node_id')->comment('节点ID');
            $table->unsignedInteger('delay')->default(0)->comment('延迟(ms)');
            $table->unsignedTinyInteger('success_rate')->default(0)->comment('连接成功率(0-100)%');
            $table->string('client_ip', 45)->comment('客户端IP');
            $table->string('client_country', 2)->nullable()->comment('客户端国家代码');
            $table->string('client_city')->nullable()->comment('客户端城市');
            $table->string('client_isp')->nullable()->comment('客户端ISP');
            $table->string('user_agent')->nullable()->comment('用户代理');
            $table->string('platform')->nullable()->comment('平台: ios/android/windows/macos/linux');
            $table->string('app_version')->nullable()->comment('APP版本');
            $table->json('metadata')->nullable()->comment('其他数据');
            $table->timestamp('created_at')->useCurrent()->comment('上报时间');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // 索引
            $table->index('user_id');
            $table->index('node_id');
            $table->index('client_ip');
            $table->index('created_at');
            $table->index(['user_id', 'node_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_node_performance_report');
    }
};