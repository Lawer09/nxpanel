<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_performance_aggregated', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('日期');
            $table->unsignedTinyInteger('hour')->comment('小时 0-23');
            $table->unsignedTinyInteger('minute')->comment('分钟（5分钟粒度：0,5,10,...55）');
            $table->unsignedInteger('node_id')->comment('节点ID');
            $table->string('client_country', 2)->nullable()->comment('客户端国家缩写');
            $table->string('client_city', 100)->nullable()->comment('客户端城市');
            $table->string('platform', 100)->nullable()->comment('客户端平台');
            $table->string('client_isp', 255)->nullable()->comment('客户端ISP');
            $table->decimal('avg_success_rate', 5, 2)->default(0)->comment('平均连接成功率');
            $table->decimal('avg_delay', 10, 2)->default(0)->comment('平均延迟(ms)');
            $table->unsignedInteger('total_count')->default(0)->comment('总上报数量');
            $table->timestamp('created_at')->useCurrent();

            // 唯一索引：同一时间窗口 + 维度组合唯一
            $table->unique(
                ['date', 'hour', 'minute', 'node_id', 'client_country', 'client_city', 'platform', 'client_isp'],
                'uq_perf_agg_dimension'
            );

            $table->index('node_id');
            $table->index('date');
            $table->index(['node_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_node_performance_aggregated');
    }
};
