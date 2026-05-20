<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create DNS tool related tables.
     */
    public function up(): void
    {
        if (!Schema::hasTable('dns_provider')) {
            Schema::create('dns_provider', function (Blueprint $table) {
                $table->bigIncrements('id')->comment('主键');
                $table->string('name', 128)->comment('提供商名称');
                $table->string('tags', 255)->default('')->comment('业务标签');
                $table->string('note', 255)->default('')->comment('备注');
                $table->string('official_website', 255)->nullable()->comment('官方网站');
                $table->string('api_host', 255)->nullable()->comment('API地址（完整URL）');
                $table->integer('request_timeout')->default(15)->comment('请求超时秒');
                $table->integer('rate_limit_per_minute')->default(60)->comment('每分钟限流');
                $table->timestamps();

                $table->unique('name', 'uk_provider_name');
            });
        }

        if (!Schema::hasTable('dns_provider_accounts')) {
            Schema::create('dns_provider_accounts', function (Blueprint $table) {
                $table->bigIncrements('id')->comment('主键');
                $table->string('provider_code', 32)->comment('平台代码');
                $table->string('account_name', 128)->comment('账号名称');
                $table->string('tags', 255)->default('')->comment('业务标签');
                $table->string('note', 255)->default('')->comment('备注');
                $table->json('config_json')->nullable()->comment('账号鉴权与配置');
                $table->enum('status', ['active', 'disabled'])->default('active')->comment('账号状态');
                $table->dateTime('last_synced_at')->nullable()->comment('最近同步时间');
                $table->timestamps();

                $table->unique('account_name', 'uk_account_name');
                $table->index(['provider_code', 'status'], 'idx_provider_status');
            });
        }

        if (!Schema::hasTable('dns_domains')) {
            Schema::create('dns_domains', function (Blueprint $table) {
                $table->bigIncrements('id')->comment('主键');
                $table->unsignedBigInteger('provider_account_id')->comment('关联账号ID');
                $table->string('provider_code', 32)->comment('平台代码');
                $table->string('domain_name', 255)->comment('根域名');
                $table->string('tags', 255)->default('')->comment('业务标签');
                $table->string('note', 255)->default('')->comment('备注');
                $table->string('remote_id', 128)->nullable()->comment('平台域名ID');
                $table->enum('sync_status', ['active', 'disabled', 'missing'])->default('active')->comment('同步状态');
                $table->tinyInteger('is_available')->default(1)->comment('是否可分配');
                $table->dateTime('last_synced_at')->nullable()->comment('最近同步时间');
                $table->timestamps();

                $table->unique(['provider_account_id', 'domain_name'], 'uk_provider_domain');
                $table->index('domain_name', 'idx_domain_name');
                $table->index(['is_available', 'sync_status'], 'idx_available');
                $table->foreign('provider_account_id', 'fk_domains_provider_account')
                    ->references('id')
                    ->on('dns_provider_accounts');
            });
        }

        if (!Schema::hasTable('dns_ip_bindings')) {
            Schema::create('dns_ip_bindings', function (Blueprint $table) {
                $table->bigIncrements('id')->comment('主键');
                $table->unsignedBigInteger('provider_account_id')->comment('关联账号ID');
                $table->unsignedBigInteger('domain_id')->comment('关联根域名ID');
                $table->string('subdomain', 255)->comment('子域名前缀');
                $table->string('fqdn', 512)->comment('完整域名');
                $table->string('ipv4', 45)->comment('IPv4地址');
                $table->integer('ttl')->default(600)->comment('TTL秒');
                $table->string('tags', 255)->default('')->comment('业务标签');
                $table->string('note', 255)->default('')->comment('备注');
                $table->string('remote_record_id', 128)->nullable()->comment('平台记录ID');
                $table->enum('status', ['active', 'released'])->default('active')->comment('绑定状态');
                $table->timestamps();

                $table->unique('fqdn', 'uk_fqdn');
                $table->index(['ipv4', 'status'], 'idx_ipv4_status');
                $table->index(['domain_id', 'subdomain', 'status'], 'idx_domain_sub');
                $table->index(['provider_account_id', 'status'], 'idx_provider_status');
                $table->foreign('provider_account_id', 'fk_bind_provider_account')
                    ->references('id')
                    ->on('dns_provider_accounts');
                $table->foreign('domain_id', 'fk_bind_domain')
                    ->references('id')
                    ->on('dns_domains');
            });
        }
    }

    /**
     * Drop DNS tool related tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('dns_ip_bindings');
        Schema::dropIfExists('dns_domains');
        Schema::dropIfExists('dns_provider_accounts');
        Schema::dropIfExists('dns_provider');
    }
};
