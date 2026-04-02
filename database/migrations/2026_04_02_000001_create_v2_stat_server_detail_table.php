<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_stat_server_detail', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')    ->comment('节点 ID');
            $table->string('server_type', 20)       ->comment('节点协议类型');
            $table->unsignedBigInteger('u')          ->default(0)->comment('上行流量（字节）');
            $table->unsignedBigInteger('d')          ->default(0)->comment('下行流量（字节）');
            // 时间维度字段
            $table->unsignedSmallInteger('year')    ->comment('年，如 2026');
            $table->unsignedTinyInteger('month')    ->comment('月，1–12');
            $table->unsignedTinyInteger('day')      ->comment('日，1–31');
            $table->unsignedTinyInteger('hour')     ->comment('时，0–23');
            $table->unsignedTinyInteger('minute')   ->comment('分（已归整到分钟）0–59');
            // 分钟级时间戳（归整到每分钟整点），用于精确查询和去重
            $table->unsignedInteger('record_at')    ->comment('分钟级时间戳（Unix，已 floor 到分钟）');
            $table->unsignedInteger('created_at')   ->default(0);
            $table->unsignedInteger('updated_at')   ->default(0);

            // 唯一约束：同一节点同一分钟只有一条记录（累加）
            $table->unique(['server_id', 'server_type', 'record_at'], 'uq_server_minute');

            $table->index('record_at');
            $table->index('server_id');
            $table->index(['server_id', 'record_at']);
            $table->index(['year', 'month', 'day', 'hour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_stat_server_detail');
    }
};
