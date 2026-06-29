<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add indexes for Firebase analytics filter, ranking, and detail queries.
     */
    public function up(): void
    {
        $this->addIndex('firebase_event_common', 'idx_fa_common_event_time_filter', function (Blueprint $table) {
            $table->index(['event_time_ms', 'app_id', 'platform', 'app_version', 'user_country'], 'idx_fa_common_event_time_filter');
        });

        $this->addIndex('firebase_event_common', 'idx_fa_common_region_received', function (Blueprint $table) {
            $table->index(['received_at', 'user_country', 'user_region'], 'idx_fa_common_region_received');
        });

        $this->addIndex('firebase_event_vpn_session', 'idx_fa_session_node_connect', function (Blueprint $table) {
            $table->index(['node_id', 'connect_ms'], 'idx_fa_session_node_connect');
        });

        $this->addIndex('firebase_event_vpn_session', 'idx_fa_session_protocol_error', function (Blueprint $table) {
            $table->index(['protocol', 'error_code'], 'idx_fa_session_protocol_error');
        });

        $this->addIndex('firebase_event_vpn_probe_result', 'idx_fa_probe_result_event', function (Blueprint $table) {
            $table->index(['event_id'], 'idx_fa_probe_result_event');
        });

        $this->addIndex('firebase_event_vpn_probe_result', 'idx_fa_probe_node_success_error', function (Blueprint $table) {
            $table->index(['node_id', 'success', 'error_code'], 'idx_fa_probe_node_success_error');
        });

        $this->addIndex('firebase_event_vpn_probe_result', 'idx_fa_probe_node_latency', function (Blueprint $table) {
            $table->index(['node_id', 'latency_ms'], 'idx_fa_probe_node_latency');
        });

        $this->addIndex('firebase_report_user_summary', 'idx_fa_user_date_platform_country_net', function (Blueprint $table) {
            $table->index(['date', 'hour', 'platform', 'country', 'network_type'], 'idx_fa_user_date_platform_country_net');
        });

        $this->addIndex('firebase_report_node', 'idx_fa_node_date_country_protocol', function (Blueprint $table) {
            $table->index(['date', 'hour', 'node_country', 'protocol'], 'idx_fa_node_date_country_protocol');
        });
    }

    /**
     * Drop Firebase analytics query indexes added by this migration.
     */
    public function down(): void
    {
        $this->dropIndex('firebase_report_node', 'idx_fa_node_date_country_protocol');
        $this->dropIndex('firebase_report_user_summary', 'idx_fa_user_date_platform_country_net');
        $this->dropIndex('firebase_event_vpn_probe_result', 'idx_fa_probe_node_latency');
        $this->dropIndex('firebase_event_vpn_probe_result', 'idx_fa_probe_node_success_error');
        $this->dropIndex('firebase_event_vpn_probe_result', 'idx_fa_probe_result_event');
        $this->dropIndex('firebase_event_vpn_session', 'idx_fa_session_protocol_error');
        $this->dropIndex('firebase_event_vpn_session', 'idx_fa_session_node_connect');
        $this->dropIndex('firebase_event_common', 'idx_fa_common_region_received');
        $this->dropIndex('firebase_event_common', 'idx_fa_common_event_time_filter');
    }

    private function addIndex(string $table, string $indexName, callable $callback): void
    {
        if (!Schema::hasTable($table) || $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, $callback);
    }

    private function dropIndex(string $table, string $indexName): void
    {
        if (!Schema::hasTable($table) || !$this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");
            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $result = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $indexName]
        );

        return $result !== null;
    }
};
