<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_traffic_aggregated', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('日期（以 arise_timestamp 为准）');
            $table->unsignedTinyInteger('hour')->comment('小时 0-23');
            $table->unsignedTinyInteger('minute')->comment('分钟（5分钟粒度）');
            $table->unsignedInteger('node_id')->default(0)->comment('内部节点ID，外部节点为0');
            $table->string('node_ip', 255)->nullable()->comment('节点IP或域名（外部节点标识）');
            $table->string('client_country', 2)->nullable()->comment('客户端国家缩写');
            $table->string('platform', 100)->nullable()->comment('客户端平台');
            $table->string('client_isp', 255)->nullable()->comment('客户端ISP');
            $table->string('app_id', 255)->nullable()->comment('App包名');
            $table->string('app_version', 50)->nullable()->comment('App版本');
            $table->unsignedBigInteger('total_usage_seconds')->default(0)->comment('总使用时长（秒）');
            $table->decimal('total_usage_mb', 20, 3)->default(0)->comment('总使用流量（MB）');
            $table->unsignedInteger('report_count')->default(0)->comment('上报次数');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['date', 'hour', 'minute', 'node_id', 'node_ip', 'client_country', 'platform', 'client_isp', 'app_id', 'app_version'],
                'uq_node_traffic_agg_dimension'
            );

            $table->index(['date', 'node_id'], 'idx_traffic_date_node');
            $table->index(['date', 'node_ip'], 'idx_traffic_date_node_ip');
            $table->index(['date', 'app_id', 'app_version'], 'idx_traffic_date_app');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_node_traffic_aggregated');
    }
};
