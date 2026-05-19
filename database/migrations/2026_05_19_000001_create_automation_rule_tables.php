<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('module', 64)->comment('规则所属模块，例如 traffic_platform');
            $table->string('name', 100)->comment('规则名称');
            $table->string('target_type', 64)->default('traffic_platform_account')->comment('目标类型');
            $table->string('description', 255)->nullable()->comment('规则说明');
            $table->string('condition_logic', 10)->default('all')->comment('条件关系：all/any');
            $table->json('target_scope_json')->nullable()->comment('目标范围');
            $table->json('conditions_json')->comment('条件列表');
            $table->json('actions_json')->comment('动作列表');
            $table->integer('cooldown_seconds')->default(3600)->comment('冷却秒数');
            $table->tinyInteger('recovery_enabled')->default(1)->comment('是否启用恢复通知');
            $table->tinyInteger('enabled')->default(1)->comment('是否启用');
            $table->dateTime('created_at')->comment('创建时间');
            $table->dateTime('updated_at')->comment('更新时间');

            $table->index(['module', 'enabled'], 'idx_automation_rule_module_enabled');
            $table->index(['target_type', 'enabled'], 'idx_automation_rule_target_enabled');
        });

        Schema::create('automation_rule_states', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rule_id')->comment('规则ID');
            $table->string('target_type', 64)->comment('目标类型');
            $table->string('target_id', 64)->comment('目标ID');
            $table->string('status', 20)->default('normal')->comment('状态：normal/alerting');
            $table->string('last_fingerprint', 64)->nullable()->comment('最近一次命中的条件指纹');
            $table->dateTime('last_evaluation_at')->nullable()->comment('最近评估时间');
            $table->dateTime('last_triggered_at')->nullable()->comment('最近告警时间');
            $table->dateTime('last_recovered_at')->nullable()->comment('最近恢复时间');
            $table->dateTime('suppress_until')->nullable()->comment('抑制到期时间');
            $table->json('extra_json')->nullable()->comment('扩展信息');
            $table->dateTime('created_at')->comment('创建时间');
            $table->dateTime('updated_at')->comment('更新时间');

            $table->unique(['rule_id', 'target_type', 'target_id'], 'uk_automation_rule_target');
            $table->index(['status', 'suppress_until'], 'idx_automation_state_status_suppress');
        });

        Schema::create('automation_executions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rule_id')->comment('规则ID');
            $table->string('module', 64)->comment('模块');
            $table->string('target_type', 64)->comment('目标类型');
            $table->string('target_id', 64)->comment('目标ID');
            $table->string('status', 20)->comment('执行结果：triggered/recovered/skipped/failed');
            $table->json('metrics_snapshot')->nullable()->comment('评估指标快照');
            $table->json('matched_conditions')->nullable()->comment('命中明细');
            $table->json('actions_snapshot')->nullable()->comment('执行动作');
            $table->json('action_results')->nullable()->comment('动作执行结果');
            $table->text('error_message')->nullable()->comment('错误信息');
            $table->dateTime('executed_at')->comment('执行时间');
            $table->dateTime('created_at')->comment('创建时间');

            $table->index(['rule_id', 'executed_at'], 'idx_automation_exec_rule_time');
            $table->index(['module', 'status', 'executed_at'], 'idx_automation_exec_module_status_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
        Schema::dropIfExists('automation_rule_states');
        Schema::dropIfExists('automation_rules');
    }
};
