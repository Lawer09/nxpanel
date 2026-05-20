<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Update dns_ip_bindings schema to new record model.
     */
    public function up(): void
    {
        if (!Schema::hasTable('dns_ip_bindings')) {
            return;
        }

        Schema::table('dns_ip_bindings', function (Blueprint $table) {
            if (!Schema::hasColumn('dns_ip_bindings', 'record_name')) {
                $table->string('record_name', 255)->default('@')->comment('记录名，@表示根记录')->after('subdomain');
            }
            if (!Schema::hasColumn('dns_ip_bindings', 'record_type')) {
                $table->string('record_type', 16)->default('A')->comment('记录类型，仅同步A')->after('ipv4');
            }
            if (!Schema::hasColumn('dns_ip_bindings', 'proxied')) {
                $table->tinyInteger('proxied')->default(0)->comment('是否代理（Cloudflare）')->after('ttl');
            }
            if (!Schema::hasColumn('dns_ip_bindings', 'raw_record')) {
                $table->json('raw_record')->nullable()->comment('上游原始记录')->after('proxied');
            }
            if (!Schema::hasColumn('dns_ip_bindings', 'remote_key')) {
                $table->string('remote_key', 255)->nullable()->comment('远端记录唯一键（record_id 或 name|ip）')->after('note');
            }
            if (!Schema::hasColumn('dns_ip_bindings', 'synced_at')) {
                $table->dateTime('synced_at')->nullable()->comment('最近同步时间')->after('status');
            }
            if (!Schema::hasColumn('dns_ip_bindings', 'released_at')) {
                $table->dateTime('released_at')->nullable()->comment('释放时间')->after('synced_at');
            }
        });

        DB::statement("UPDATE dns_ip_bindings SET record_name = CASE WHEN subdomain IS NULL OR subdomain = '' THEN '@' ELSE subdomain END WHERE record_name = '@' OR record_name = ''");

        Schema::table('dns_ip_bindings', function (Blueprint $table) {
            try {
                $table->unique(['provider_account_id', 'domain_id', 'remote_key'], 'uk_provider_domain_remote_key');
            } catch (\Throwable $e) {
            }

            try {
                $table->index(['provider_account_id', 'remote_record_id'], 'idx_provider_record');
            } catch (\Throwable $e) {
            }
        });
    }

    /**
     * Rollback dns_ip_bindings new fields.
     */
    public function down(): void
    {
        if (!Schema::hasTable('dns_ip_bindings')) {
            return;
        }

        Schema::table('dns_ip_bindings', function (Blueprint $table) {
            try {
                $table->dropUnique('uk_provider_domain_remote_key');
            } catch (\Throwable $e) {
            }

            try {
                $table->dropIndex('idx_provider_record');
            } catch (\Throwable $e) {
            }

            if (Schema::hasColumn('dns_ip_bindings', 'released_at')) {
                $table->dropColumn('released_at');
            }
            if (Schema::hasColumn('dns_ip_bindings', 'synced_at')) {
                $table->dropColumn('synced_at');
            }
            if (Schema::hasColumn('dns_ip_bindings', 'remote_key')) {
                $table->dropColumn('remote_key');
            }
            if (Schema::hasColumn('dns_ip_bindings', 'raw_record')) {
                $table->dropColumn('raw_record');
            }
            if (Schema::hasColumn('dns_ip_bindings', 'proxied')) {
                $table->dropColumn('proxied');
            }
            if (Schema::hasColumn('dns_ip_bindings', 'record_type')) {
                $table->dropColumn('record_type');
            }
            if (Schema::hasColumn('dns_ip_bindings', 'record_name')) {
                $table->dropColumn('record_name');
            }
        });
    }
};
