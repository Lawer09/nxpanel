<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_probe_aggregated', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('日期');
            $table->unsignedTinyInteger('hour')->comment('小时 0-23');
            $table->unsignedTinyInteger('minute')->comment('分钟（5分钟粒度）');
            $table->unsignedInteger('node_id')->default(0)->comment('内部节点ID，外部节点为0');
            $table->string('node_ip', 255)->nullable()->comment('节点IP或域名（外部节点标识）');
            $table->string('client_country', 2)->nullable()->comment('客户端国家缩写');
            $table->string('platform', 100)->nullable()->comment('客户端平台');
            $table->string('client_isp', 255)->nullable()->comment('客户端ISP');
            $table->string('app_id', 255)->nullable()->comment('App包名');
            $table->string('app_version', 50)->nullable()->comment('App版本');
            $table->string('probe_stage', 32)->comment('探测阶段');
            $table->string('status', 16)->comment('探测状态');
            $table->string('error_code', 64)->nullable()->comment('错误码');
            $table->char('dimension_hash', 32)->comment('聚合维度哈希');
            $table->unsignedInteger('total_count')->default(0)->comment('总上报数量');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('dimension_hash', 'uq_node_probe_agg_dimension_hash');

            $table->index(['date', 'node_id'], 'idx_probe_date_node');
            $table->index(['date', 'node_ip'], 'idx_probe_date_node_ip');
            $table->index(['date', 'error_code'], 'idx_probe_date_error');
            $table->index(['date', 'probe_stage', 'status'], 'idx_probe_date_stage_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_node_probe_aggregated');
    }
};
