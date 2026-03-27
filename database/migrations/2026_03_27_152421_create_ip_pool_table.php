<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_ip_pool', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique()->comment('IP地址');
            $table->string('hostname')->nullable()->comment('主机名');
            $table->string('city')->nullable()->comment('城市');
            $table->string('region')->nullable()->comment('地区');
            $table->string('country', 2)->nullable()->comment('国家代码');
            $table->string('loc')->nullable()->comment('坐标 (lat,long)');
            $table->string('org')->nullable()->comment('组织/ISP信息');
            $table->string('postal')->nullable()->comment('邮编');
            $table->string('timezone')->nullable()->comment('时区');
            $table->string('readme_url')->nullable()->comment('信息URL');
            
            // 性能指标
            $table->unsignedTinyInteger('score')->default(100)->comment('评分(0-100)');
            $table->integer('load')->default(0)->comment('当前负载');
            $table->unsignedSmallInteger('max_load')->default(100)->comment('最大负载');
            $table->unsignedTinyInteger('success_rate')->default(100)->comment('成功率(0-100)%');
            $table->string('status', 20)->default('active')->comment('状态: active/cooldown');
            $table->unsignedTinyInteger('risk_level')->default(0)->comment('风险值(0-100)');
            
            // 系统字段
            $table->unsignedInteger('total_requests')->default(0)->comment('总请求数');
            $table->unsignedInteger('successful_requests')->default(0)->comment('成功请求数');
            $table->integer('last_used_at')->nullable()->comment('最后使用时间');
            $table->timestamp('created_at')->useCurrent()->comment('创建时间');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');
            
            // 索引
            $table->index('status');
            $table->index('score');
            $table->index('risk_level');
            $table->index('created_at');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_ip_pool');
    }
};