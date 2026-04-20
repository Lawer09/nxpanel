<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v3_user_report_count', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('日期');
            $table->unsignedTinyInteger('hour')->comment('小时 0-23');
            $table->unsignedTinyInteger('minute')->comment('分钟（5分钟粒度：0,5,10,...55）');
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->unsignedInteger('report_count')->default(0)->comment('上报次数');
            $table->unsignedInteger('node_count')->default(0)->comment('涉及节点数');
            $table->string('platform', 100)->nullable()->comment('平台');
            $table->string('app_id', 255)->nullable()->comment('App包名');
            $table->string('app_version', 50)->nullable()->comment('App版本');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['date', 'hour', 'minute', 'user_id'], 'uq_user_report_time');
            $table->index('user_id');
            $table->index('date');
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v3_user_report_count');
    }
};
