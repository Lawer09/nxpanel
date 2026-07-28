<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Repair the user report count aggregate table when migration records drift from the real schema.
     */
    public function up(): void
    {
        if (!Schema::hasTable('v3_user_report_count')) {
            Schema::create('v3_user_report_count', function (Blueprint $table) {
                $table->id();
                $table->date('date')->comment('日期');
                $table->unsignedTinyInteger('hour')->comment('小时 0-23');
                $table->unsignedTinyInteger('minute')->comment('分钟（5分钟粒度：0,5,10,...55）');
                $table->unsignedBigInteger('user_id')->comment('用户ID');
                $table->unsignedInteger('report_count')->default(0)->comment('上报次数');
                $table->unsignedInteger('node_count')->default(0)->comment('涉及节点数');
                $table->string('client_country', 2)->nullable()->comment('客户端国家');
                $table->string('client_isp', 255)->nullable()->comment('客户端ISP');
                $table->string('platform', 100)->nullable()->comment('平台');
                $table->string('app_id', 255)->nullable()->comment('App包名');
                $table->string('app_version', 50)->nullable()->comment('App版本');
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['date', 'hour', 'minute', 'user_id'], 'uq_user_report_time');
                $table->index('user_id');
                $table->index('date');
                $table->index(['user_id', 'date']);
            });
        }

        $this->ensureMissingColumns();
        $this->ensureIndexes();
    }

    /**
     * Keep rollback non-destructive because the repaired schema belongs to historical migrations.
     */
    public function down(): void
    {
        // Intentionally left blank: dropping the table or indexes here can remove structures
        // created by earlier migrations in environments where this repair migration was a no-op.
    }

    /**
     * Add columns from later historical migrations if the table was manually recreated from an early schema.
     */
    private function ensureMissingColumns(): void
    {
        if (!Schema::hasColumn('v3_user_report_count', 'client_country')) {
            Schema::table('v3_user_report_count', function (Blueprint $table) {
                $table->string('client_country', 2)->nullable()->comment('客户端国家')->after('node_count');
            });
        }

        if (!Schema::hasColumn('v3_user_report_count', 'client_isp')) {
            Schema::table('v3_user_report_count', function (Blueprint $table) {
                $table->string('client_isp', 255)->nullable()->comment('客户端ISP')->after('client_country');
            });
        }
    }

    /**
     * Add covering indexes expected by performance and project aggregate queries.
     */
    private function ensureIndexes(): void
    {
        foreach ($this->performanceIndexes() as $indexName => $columns) {
            if ($this->indexExists('v3_user_report_count', $indexName)) {
                continue;
            }

            Schema::table('v3_user_report_count', function (Blueprint $table) use ($columns, $indexName) {
                $table->index($columns, $indexName);
            });
        }
    }

    /**
     * Return the performance indexes added after the original table migration.
     */
    private function performanceIndexes(): array
    {
        return [
            'idx_urc_date_user' => ['date', 'user_id'],
            'idx_urc_date_hour_user' => ['date', 'hour', 'user_id'],
            'idx_urc_app_date_hour_user' => ['app_id', 'date', 'hour', 'user_id'],
            'idx_urc_platform_date_hour_user' => ['platform', 'date', 'hour', 'user_id'],
            'idx_urc_app_platform_date_hour_user' => ['app_id', 'platform', 'date', 'hour', 'user_id'],
            'idx_urc_user_date_hour' => ['user_id', 'date', 'hour'],
        ];
    }

    /**
     * Check whether an index exists on the active database connection.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            return collect(DB::select("PRAGMA index_list('{$table}')"))
                ->contains(static fn ($index): bool => ($index->name ?? null) === $indexName);
        }

        return DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $indexName]
        ) !== null;
    }
};
