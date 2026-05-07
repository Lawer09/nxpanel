<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_main_report_aggregated', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('hour');
            $table->unsignedTinyInteger('minute');
            $table->string('scope', 16)->comment('client|node');

            $table->unsignedInteger('node_id')->default(0);
            $table->string('node_name', 255)->nullable();
            $table->string('node_host', 255)->nullable();
            $table->string('machine_ip', 255)->nullable();
            $table->string('machine_ip_isp', 255)->nullable();
            $table->string('node_protocol', 32)->nullable();

            $table->string('app_id', 255)->nullable();
            $table->string('app_version', 50)->nullable();
            $table->string('platform', 100)->nullable();
            $table->string('client_country', 2)->nullable();
            $table->string('client_isp', 255)->nullable();

            $table->decimal('delay_weighted_sum', 20, 4)->default(0);
            $table->unsignedBigInteger('delay_weight')->default(0);
            $table->unsignedBigInteger('success_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->unsignedBigInteger('node_connect_error_count')->default(0);
            $table->unsignedBigInteger('post_connect_probe_error_count')->default(0);

            $table->decimal('client_report_traffic_usage_mb', 20, 3)->default(0);
            $table->unsignedBigInteger('client_report_usage_seconds')->default(0);
            $table->unsignedBigInteger('client_report_count')->default(0);

            $table->unsignedBigInteger('node_push_traffic_u_bytes')->nullable();
            $table->unsignedBigInteger('node_push_traffic_d_bytes')->nullable();
            $table->unsignedBigInteger('node_push_traffic_total_bytes')->nullable();

            $table->integer('bandwidth')->nullable();
            $table->integer('up_bandwidth')->nullable();
            $table->integer('down_bandwidth')->nullable();

            $table->char('dimension_hash', 32);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('dimension_hash', 'uq_node_main_report_dimension_hash');
            $table->index(['date', 'hour', 'minute'], 'idx_node_main_report_time');
            $table->index(['scope', 'date'], 'idx_node_main_report_scope_date');
            $table->index(['date', 'node_id'], 'idx_node_main_report_date_node');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_node_main_report_aggregated');
    }
};
