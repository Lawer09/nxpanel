<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给节点模板表新增 generation_options 列
 *
 * generation_options JSON 结构（全部字段可选）：
 * {
 *   "port_random":          true,   // 客户端端口是否随机生成
 *   "server_port_random":   true,   // 服务端端口是否随机生成（为 false 时与客户端端口相同）
 *   "port_same":            false,  // 随机生成时，客户端端口与服务端端口是否保持一致
 *   "port_min":             10000,  // 随机端口范围下限（默认 10000）
 *   "port_max":             60000,  // 随机端口范围上限（默认 60000）
 *   "reality_key_random":   true,   // reality public_key / private_key 是否随机生成
 *   "reality_shortid_random": true  // reality short_id 是否随机生成
 * }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_server_template', function (Blueprint $table) {
            $table->json('generation_options')
                ->nullable()
                ->after('cert_config')
                ->comment('节点参数生成选项（端口/reality密钥随机化等）');
        });
    }

    public function down(): void
    {
        Schema::table('v2_server_template', function (Blueprint $table) {
            $table->dropColumn('generation_options');
        });
    }
};
