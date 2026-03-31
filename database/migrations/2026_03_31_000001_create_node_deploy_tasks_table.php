<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('node_deploy_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id')->nullable()->comment('批次ID');
            $table->unsignedBigInteger('machine_id')->comment('目标机器ID');
            $table->unsignedBigInteger('server_id')->nullable()->comment('成功后关联节点ID');
            $table->enum('status', ['pending', 'running', 'success', 'failed'])
                  ->default('pending')->comment('任务状态');
            $table->json('deploy_config')->comment('部署配置快照');
            $table->longText('output')->nullable()->comment('执行输出');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('batch_id');
            $table->index('machine_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_deploy_tasks');
    }
};
