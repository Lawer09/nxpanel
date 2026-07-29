<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'v3_user_preferences';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->comment('用户个人偏好配置表，支持后续扩展其他用户级配置');
            $table->id()->comment('主键ID');
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->string('preference_key', 191)->comment('偏好配置Key');
            $table->json('preference_value')->comment('偏好配置JSON值');
            $table->string('value_hash', 64)->comment('偏好配置稳定JSON SHA-256哈希');
            $table->timestamp('created_at')->useCurrent()->comment('创建时间');
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->comment('更新时间');

            $table->unique(['user_id', 'preference_key'], 'uq_v3_user_preferences_user_key');
            $table->index('user_id', 'idx_v3_user_preferences_user_id');
            $table->index('preference_key', 'idx_v3_user_preferences_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
