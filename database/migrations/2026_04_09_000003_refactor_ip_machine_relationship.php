<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * 重构IP和机器的关联关系，使用中间表实现多对多关系
     */
    public function up(): void
    {
        // 1. 创建 ip_machine 关联表
        Schema::create('ip_machine', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ip_id')->comment('IP池ID');
            $table->unsignedBigInteger('machine_id')->comment('机器ID');
            $table->boolean('is_primary')->default(false)->comment('是否为主IP');
            $table->string('bind_status')->default('active')->comment('绑定状态: active, inactive');
            $table->timestamp('bound_at')->nullable()->comment('绑定时间');
            $table->timestamp('unbound_at')->nullable()->comment('解绑时间');
            $table->timestamps();

            // 外键约束
            $table->foreign('ip_id')
                ->references('id')
                ->on('v2_ip_pool')
                ->onDelete('cascade');
            
            $table->foreign('machine_id')
                ->references('id')
                ->on('machines')
                ->onDelete('cascade');

            // 唯一索引：同一个IP不能同时绑定到同一台机器多次
            $table->unique(['ip_id', 'machine_id']);
            
            // 索引优化查询
            $table->index(['machine_id', 'is_primary']);
            $table->index(['ip_id', 'bind_status']);
        });

        // 2. 迁移现有数据
        // 从 machines.ip_id 迁移到 ip_machine 表
        DB::statement("
            INSERT INTO ip_machine (ip_id, machine_id, is_primary, bind_status, bound_at, created_at, updated_at)
            SELECT 
                ip_id,
                id as machine_id,
                1 as is_primary,
                'active' as bind_status,
                NOW() as bound_at,
                NOW() as created_at,
                NOW() as updated_at
            FROM machines
            WHERE ip_id IS NOT NULL
        ");

        // 从 v2_ip_pool.machine_id 迁移到 ip_machine 表（如果不存在）
        DB::statement("
            INSERT INTO ip_machine (ip_id, machine_id, is_primary, bind_status, bound_at, created_at, updated_at)
            SELECT 
                ip.id as ip_id,
                ip.machine_id,
                0 as is_primary,
                'active' as bind_status,
                NOW() as bound_at,
                NOW() as created_at,
                NOW() as updated_at
            FROM v2_ip_pool ip
            WHERE ip.machine_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM ip_machine im 
                WHERE im.ip_id = ip.id AND im.machine_id = ip.machine_id
            )
        ");

        // 3. 删除旧的外键和字段
        Schema::table('machines', function (Blueprint $table) {
            if (Schema::hasColumn('machines', 'ip_id')) {
                $table->dropForeign(['ip_id']);
                $table->dropColumn('ip_id');
            }
        });

        Schema::table('v2_ip_pool', function (Blueprint $table) {
            if (Schema::hasColumn('v2_ip_pool', 'machine_id')) {
                $table->dropColumn('machine_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // 1. 恢复旧字段
        Schema::table('machines', function (Blueprint $table) {
            $table->unsignedBigInteger('ip_id')
                ->nullable()
                ->comment('Bound IP from IP pool')
                ->after('ip_address');
            
            $table->foreign('ip_id')
                ->references('id')
                ->on('v2_ip_pool')
                ->onDelete('set null');
        });

        Schema::table('v2_ip_pool', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id')
                ->nullable()
                ->comment('Bound machine ID')
                ->after('bandwidth');
        });

        // 2. 迁移数据回去（只迁移主IP）
        DB::statement("
            UPDATE machines m
            INNER JOIN ip_machine im ON m.id = im.machine_id
            SET m.ip_id = im.ip_id
            WHERE im.is_primary = 1 AND im.bind_status = 'active'
        ");

        // 3. 删除中间表
        Schema::dropIfExists('ip_machine');
    }
};
