<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_providers', function (Blueprint $table) {
            $table->string('driver')->nullable()->after('type')
                ->comment('云驱动标识，如 zenlayer / aliyun');

            $table->text('api_credentials')->nullable()->after('driver')
                ->comment('API 凭证（加密存储）');

            $table->json('supported_operations')->nullable()->after('api_credentials')
                ->comment('该服务商支持的操作类型列表，如 ["listInstances","bindElasticIp"]');
        });
    }

    public function down(): void
    {
        Schema::table('v2_providers', function (Blueprint $table) {
            $table->dropColumn(['driver', 'api_credentials', 'supported_operations']);
        });
    }
};
