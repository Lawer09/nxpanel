<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('机器名称');
            $table->string('hostname')->unique()->comment('主机名');
            $table->string('ip_address')->comment('IP地址');
            $table->integer('port')->default(22)->comment('端口');
            $table->string('username')->comment('用户名');
            $table->text('password')->nullable()->comment('密码 (加密存储)');
            $table->text('private_key')->nullable()->comment('SSH私钥');
            $table->enum('status', ['online', 'offline', 'error'])->default('offline')->comment('状态');
            $table->string('os_type')->nullable()->comment('操作系统类型');
            $table->string('cpu_cores')->nullable()->comment('CPU核心数');
            $table->string('memory')->nullable()->comment('内存大小');
            $table->string('disk')->nullable()->comment('磁盘大小');
            $table->timestamp('last_check_at')->nullable()->comment('最后检查时间');
            $table->text('description')->nullable()->comment('描述');
            $table->unsignedTinyInteger('is_active')->default(1)->comment('是否激活');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('status');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};