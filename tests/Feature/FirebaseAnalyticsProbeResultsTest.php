<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FirebaseAnalyticsProbeResultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        Carbon::setTestNow(Carbon::parse('2026-06-29 10:30:00'));
        $this->createFirebaseTables();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_probe_results_defaults_to_today_and_returns_detail_rows(): void
    {
        $this->seedProbeResult('evt_today', [
            'received_at' => '2026-06-29 08:00:00',
            'probe_id' => 'probe-today',
            'node_id' => 'node-sg-01',
            'success' => 1,
            'latency_ms' => 120,
        ]);
        $this->seedProbeResult('evt_yesterday', [
            'received_at' => '2026-06-28 23:59:59',
            'probe_id' => 'probe-yesterday',
            'node_id' => 'node-us-01',
            'success' => 0,
        ]);

        $this->getJson($this->adminFirebaseUri('vpn-probe/results'))
            ->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 20)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.event_id', 'evt_today')
            ->assertJsonPath('data.items.0.probe_id', 'probe-today')
            ->assertJsonPath('data.items.0.node_id', 'node-sg-01')
            ->assertJsonPath('data.items.0.latency_ms', 120);
    }

    public function test_probe_results_filters_and_paginates(): void
    {
        $this->seedProbeResult('evt_match', [
            'received_at' => '2026-06-29 09:00:00',
            'probe_id' => 'probe-001',
            'node_id' => 'node-a',
            'success' => 0,
            'error_code' => 'TIMEOUT',
            'latency_ms' => 500,
        ]);
        $this->seedProbeResult('evt_other', [
            'received_at' => '2026-06-29 09:05:00',
            'probe_id' => 'probe-002',
            'node_id' => 'node-b',
            'success' => 1,
            'error_code' => null,
            'latency_ms' => 50,
        ]);

        $response = $this->getJson($this->adminFirebaseUri('vpn-probe/results') . '?' . http_build_query([
            'event_id' => 'evt_match',
            'probe_id' => 'probe-001',
            'node_id' => 'node-a',
            'success' => false,
            'error_code' => 'TIMEOUT',
            'page' => 1,
            'page_size' => 1,
            'sort_by' => 'latency_ms',
            'order' => 'asc',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.event_id', 'evt_match')
            ->assertJsonPath('data.items.0.success', 0)
            ->assertJsonPath('data.items.0.error_code', 'TIMEOUT')
            ->assertJsonPath('data.items.0.tcp_connect_ms', 80)
            ->assertJsonPath('data.items.0.tls_hk_ms', 90)
            ->assertJsonPath('data.items.0.proxy_hk_ms', 100)
            ->assertJsonPath('data.items.0.timeout_ms', 3000);
    }

    public function test_probe_results_validates_sorting_parameters(): void
    {
        $this->getJson($this->adminFirebaseUri('vpn-probe/results') . '?' . http_build_query([
            'sort_by' => 'unsafe_column',
            'order' => 'sideways',
        ]))->assertStatus(422);
    }

    public function test_nodes_quality_rank_supports_computed_session_sorts(): void
    {
        $this->seedSession('evt_session_a', [
            'node_id' => 'node-a',
            'protocol' => 'vless',
            'success' => 1,
            'connect_ms' => 100,
            'error_code' => null,
        ]);
        $this->seedSession('evt_session_b', [
            'node_id' => 'node-b',
            'protocol' => 'trojan',
            'success' => 0,
            'connect_ms' => 900,
            'error_code' => 'TCP_TIMEOUT',
        ]);

        $this->getJson($this->adminFirebaseUri('nodes/quality-rank') . '?' . http_build_query([
            'source' => 'session',
            'sort_by' => 'p95_connect_ms',
            'order' => 'desc',
        ]))
            ->assertOk()
            ->assertJsonPath('data.source', 'session')
            ->assertJsonPath('data.items.0.node_id', 'node-b')
            ->assertJsonPath('data.items.0.p95_connect_ms', 900)
            ->assertJsonPath('data.items.0.top_error_code', 'TCP_TIMEOUT');
    }

    public function test_nodes_quality_rank_supports_probe_source_session_count_sort(): void
    {
        $this->seedProbeResult('evt_probe_a', [
            'node_id' => 'node-a',
            'success' => 1,
            'latency_ms' => 80,
        ]);
        $this->seedProbeResult('evt_probe_b', [
            'node_id' => 'node-b',
            'success' => 0,
            'latency_ms' => 300,
            'error_code' => 'PROBE_TIMEOUT',
        ]);
        $this->seedProbeResult('evt_probe_c', [
            'node_id' => 'node-b',
            'success' => 1,
            'latency_ms' => 120,
        ]);

        $this->getJson($this->adminFirebaseUri('nodes/quality-rank') . '?' . http_build_query([
            'source' => 'probe',
            'sort_by' => 'session_count',
            'order' => 'desc',
        ]))
            ->assertOk()
            ->assertJsonPath('data.source', 'probe')
            ->assertJsonPath('data.items.0.node_id', 'node-b')
            ->assertJsonPath('data.items.0.session_count', 2)
            ->assertJsonPath('data.items.0.top_error_code', 'PROBE_TIMEOUT');
    }

    public function test_probe_node_rank_supports_computed_sorts(): void
    {
        $this->seedProbeResult('evt_probe_rank_a', [
            'node_id' => 'node-a',
            'success' => 1,
            'latency_ms' => 80,
        ]);
        $this->seedProbeResult('evt_probe_rank_b', [
            'node_id' => 'node-b',
            'success' => 0,
            'latency_ms' => 350,
            'error_code' => 'PROBE_TIMEOUT',
        ]);

        $this->getJson($this->adminFirebaseUri('vpn-probe/node-rank') . '?' . http_build_query([
            'sort_by' => 'p95_latency_ms',
            'order' => 'desc',
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.node_id', 'node-b')
            ->assertJsonPath('data.items.0.p95_latency_ms', 350)
            ->assertJsonPath('data.items.0.top_error_code', 'PROBE_TIMEOUT');
    }

    public function test_probe_node_stats_returns_paginated_node_success_metrics(): void
    {
        $this->seedProbeResult('evt_node_stats_a_success', [
            'node_id' => 'node-a',
            'node_name' => 'Node A',
            'success' => 1,
            'latency_ms' => 80,
            'received_at' => '2026-06-29 09:00:00',
        ]);
        $this->seedProbeResult('evt_node_stats_a_fail', [
            'node_id' => 'node-a',
            'node_name' => 'Node A',
            'success' => 0,
            'latency_ms' => 420,
            'error_code' => 'PROBE_TIMEOUT',
            'received_at' => '2026-06-29 09:05:00',
        ]);
        $this->seedProbeResult('evt_node_stats_b_success', [
            'node_id' => 'node-b',
            'node_name' => 'Node B',
            'success' => 1,
            'latency_ms' => 120,
            'received_at' => '2026-06-29 09:10:00',
        ]);

        $this->getJson($this->adminFirebaseUri('vpn-probe/node-stats') . '?' . http_build_query([
            'sort_by' => 'fail_count',
            'order' => 'desc',
            'page' => 1,
            'page_size' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.items.0.node_id', 'node-a')
            ->assertJsonPath('data.items.0.test_count', 2)
            ->assertJsonPath('data.items.0.success_count', 1)
            ->assertJsonPath('data.items.0.fail_count', 1)
            ->assertJsonPath('data.items.0.success_rate', 0.5)
            ->assertJsonPath('data.items.0.p95_latency_ms', 420)
            ->assertJsonPath('data.items.0.top_error_code', 'PROBE_TIMEOUT')
            ->assertJsonPath('data.items.0.last_received_at', '2026-06-29 09:05:00');
    }

    public function test_probe_node_stats_validates_sorting_parameters(): void
    {
        $this->getJson($this->adminFirebaseUri('vpn-probe/node-stats') . '?' . http_build_query([
            'sort_by' => 'unsafe_column',
            'order' => 'sideways',
        ]))->assertStatus(422);
    }

    public function test_nodes_status_merges_session_and_probe_metrics(): void
    {
        $this->seedSession('evt_node_status_session_a', [
            'node_id' => 'node-a',
            'node_name' => 'Node A',
            'success' => 0,
            'connect_ms' => 900,
            'retry_count' => 1,
            'total_bytes' => 2048,
            'error_code' => 'TCP_TIMEOUT',
            'received_at' => '2026-06-29 09:00:00',
        ]);
        $this->seedProbeResult('evt_node_status_probe_a', [
            'node_id' => 'node-a',
            'node_name' => 'Node A',
            'success' => 1,
            'latency_ms' => 80,
            'received_at' => '2026-06-29 09:05:00',
        ]);
        $this->seedProbeResult('evt_node_status_probe_b', [
            'node_id' => 'node-b',
            'node_name' => 'Node B',
            'success' => 0,
            'latency_ms' => 450,
            'error_code' => 'PROBE_TIMEOUT',
            'received_at' => '2026-06-29 09:10:00',
        ]);

        $this->getJson($this->adminFirebaseUri('nodes/status') . '?' . http_build_query([
            'sort_by' => 'diagnosis_priority',
            'order' => 'asc',
        ]))
            ->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 20)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.items.0.node_id', 'node-a')
            ->assertJsonPath('data.items.0.diagnosis_status', 'connect_gap')
            ->assertJsonPath('data.items.0.sample_scope', 'dual')
            ->assertJsonPath('data.items.0.rate_gap', 1)
            ->assertJsonPath('data.items.0.session_count', 1)
            ->assertJsonPath('data.items.0.session_success_rate', 0)
            ->assertJsonPath('data.items.0.p95_connect_ms', 900)
            ->assertJsonPath('data.items.0.session_top_error_code', 'TCP_TIMEOUT')
            ->assertJsonPath('data.items.0.probe_test_count', 1)
            ->assertJsonPath('data.items.0.probe_success_rate', 1)
            ->assertJsonPath('data.items.0.p95_latency_ms', 80)
            ->assertJsonPath('data.items.1.node_id', 'node-b')
            ->assertJsonPath('data.items.1.diagnosis_status', 'probe_risk')
            ->assertJsonPath('data.items.1.sample_scope', 'probe_only')
            ->assertJsonPath('data.items.1.probe_top_error_code', 'PROBE_TIMEOUT');
    }

    public function test_nodes_status_filters_diagnosis_and_validates_sorting_parameters(): void
    {
        $this->seedSession('evt_node_status_filter_session', [
            'node_id' => 'node-filter',
            'success' => 1,
        ]);

        $this->getJson($this->adminFirebaseUri('nodes/status') . '?' . http_build_query([
            'sample_scope' => 'session_only',
            'diagnosis_status' => 'session_only',
        ]))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.node_id', 'node-filter');

        $this->getJson($this->adminFirebaseUri('nodes/status') . '?' . http_build_query([
            'sort_by' => 'unsafe_column',
            'order' => 'sideways',
        ]))->assertStatus(422);
    }

    public function test_node_connection_summary_returns_single_node_metrics(): void
    {
        $this->seedSession('evt_conn_summary_success', [
            'node_id' => 'node-summary',
            'success' => 1,
            'connect_ms' => 100,
            'duration_ms' => 60000,
            'retry_count' => 0,
            'upload_bytes' => 1000,
            'download_bytes' => 2000,
            'total_bytes' => 3000,
            'device_id' => 'device-a',
            'received_at' => '2026-06-29 09:00:00',
        ]);
        $this->seedSession('evt_conn_summary_fail', [
            'node_id' => 'node-summary',
            'success' => 0,
            'connect_ms' => 900,
            'duration_ms' => 0,
            'retry_count' => 2,
            'upload_bytes' => 10,
            'download_bytes' => 20,
            'total_bytes' => 30,
            'device_id' => 'device-b',
            'error_code' => 'TCP_TIMEOUT',
            'received_at' => '2026-06-29 09:05:00',
        ]);

        $this->getJson($this->adminFirebaseUri('nodes/connection-summary') . '?' . http_build_query([
            'node_id' => 'node-summary',
        ]))
            ->assertOk()
            ->assertJsonPath('data.session_count', 2)
            ->assertJsonPath('data.success_count', 1)
            ->assertJsonPath('data.fail_count', 1)
            ->assertJsonPath('data.success_rate', 0.5)
            ->assertJsonPath('data.active_devices', 2)
            ->assertJsonPath('data.avg_connect_ms', 500)
            ->assertJsonPath('data.p95_connect_ms', 900)
            ->assertJsonPath('data.avg_duration_ms', 30000)
            ->assertJsonPath('data.retry_session_count', 1)
            ->assertJsonPath('data.retry_rate', 0.5)
            ->assertJsonPath('data.total_upload_bytes', 1010)
            ->assertJsonPath('data.total_download_bytes', 2020)
            ->assertJsonPath('data.total_bytes', 3030)
            ->assertJsonPath('data.top_error_code', 'TCP_TIMEOUT')
            ->assertJsonPath('data.last_received_at', '2026-06-29 09:05:00');
    }

    public function test_node_connection_error_distribution_groups_error_codes(): void
    {
        $this->seedSession('evt_conn_error_a', [
            'node_id' => 'node-errors',
            'success' => 0,
            'error_stage' => 'tcp_connect',
            'error_code' => 'TCP_TIMEOUT',
            'device_id' => 'device-a',
        ]);
        $this->seedSession('evt_conn_error_b', [
            'node_id' => 'node-errors',
            'success' => 0,
            'error_stage' => 'tcp_connect',
            'error_code' => 'TCP_TIMEOUT',
            'device_id' => 'device-b',
        ]);
        $this->seedSession('evt_conn_error_c', [
            'node_id' => 'node-errors',
            'success' => 0,
            'error_stage' => 'dns',
            'error_code' => 'DNS_FAILED',
            'device_id' => 'device-a',
        ]);

        $this->getJson($this->adminFirebaseUri('nodes/connection-error-distribution') . '?' . http_build_query([
            'node_id' => 'node-errors',
            'limit' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.error_stage', 'tcp_connect')
            ->assertJsonPath('data.items.0.error_code', 'TCP_TIMEOUT')
            ->assertJsonPath('data.items.0.count', 2)
            ->assertJsonPath('data.items.0.ratio', 0.6667)
            ->assertJsonPath('data.items.0.affected_devices', 2);
    }

    public function test_node_connection_results_filters_paginates_and_returns_detail_fields(): void
    {
        $this->seedSession('evt_conn_result_match', [
            'node_id' => 'node-results',
            'session_id' => 'sess-match',
            'connect_type' => 'retry',
            'success' => 0,
            'connect_ms' => 800,
            'duration_ms' => 0,
            'retry_count' => 2,
            'error_stage' => 'tcp_connect',
            'error_code' => 'TCP_TIMEOUT',
            'error_message' => 'connect timeout',
            'received_at' => '2026-06-29 09:00:00',
        ]);
        $this->seedSession('evt_conn_result_other', [
            'node_id' => 'node-results',
            'session_id' => 'sess-other',
            'success' => 1,
            'connect_ms' => 100,
            'error_stage' => null,
            'error_code' => null,
            'received_at' => '2026-06-29 09:10:00',
        ]);

        $this->getJson($this->adminFirebaseUri('nodes/connection-results') . '?' . http_build_query([
            'node_id' => 'node-results',
            'success' => false,
            'error_stage' => 'tcp_connect',
            'error_code' => 'TCP_TIMEOUT',
            'page' => 1,
            'page_size' => 1,
            'sort_by' => 'connect_ms',
            'order' => 'desc',
        ]))
            ->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.id', 'evt_conn_result_match')
            ->assertJsonPath('data.items.0.event_id', 'evt_conn_result_match')
            ->assertJsonPath('data.items.0.session_id', 'sess-match')
            ->assertJsonPath('data.items.0.node_id', 'node-results')
            ->assertJsonPath('data.items.0.connect_type', 'retry')
            ->assertJsonPath('data.items.0.success', 0)
            ->assertJsonPath('data.items.0.connect_ms', 800)
            ->assertJsonPath('data.items.0.retry_count', 2)
            ->assertJsonPath('data.items.0.error_stage', 'tcp_connect')
            ->assertJsonPath('data.items.0.error_code', 'TCP_TIMEOUT')
            ->assertJsonPath('data.items.0.error_message', 'connect timeout');

        $this->getJson($this->adminFirebaseUri('nodes/connection-results') . '?' . http_build_query([
            'sort_by' => 'unsafe_column',
            'order' => 'sideways',
        ]))->assertStatus(422);
    }

    public function test_region_quality_aggregates_session_metrics_without_per_region_queries(): void
    {
        $this->seedSession('evt_region_sg_success', [
            'user_country' => 'SG',
            'user_region' => 'Singapore',
            'success' => 1,
            'connect_ms' => 100,
        ]);
        $this->seedSession('evt_region_sg_fail', [
            'user_country' => 'SG',
            'user_region' => 'Singapore',
            'success' => 0,
            'connect_ms' => 300,
        ]);
        $this->seedSession('evt_region_us_success', [
            'user_country' => 'US',
            'user_region' => 'California',
            'success' => 1,
            'connect_ms' => 200,
        ]);

        $this->getJson($this->adminFirebaseUri('dashboard/region-quality') . '?' . http_build_query([
            'sort_by' => 'vpn_success_rate',
            'order' => 'asc',
        ]))
            ->assertOk()
            ->assertJsonPath('data.items.0.user_country', 'SG')
            ->assertJsonPath('data.items.0.vpn_success_rate', 0.5)
            ->assertJsonPath('data.items.0.avg_connect_ms', 200);
    }

    public function test_vpn_quality_trend_returns_p95_and_success_rate(): void
    {
        $this->seedSession('evt_trend_success', [
            'received_at' => '2026-06-29 09:00:00',
            'success' => 1,
            'connect_ms' => 100,
        ]);
        $this->seedSession('evt_trend_fail', [
            'received_at' => '2026-06-29 09:10:00',
            'success' => 0,
            'connect_ms' => 400,
        ]);

        $this->getJson($this->adminFirebaseUri('vpn-session/quality-trend') . '?' . http_build_query([
            'interval' => '1h',
        ]))
            ->assertOk()
            ->assertJsonPath('data.interval', '1h')
            ->assertJsonPath('data.items.0.session_count', 2)
            ->assertJsonPath('data.items.0.success_rate', 0.5)
            ->assertJsonPath('data.items.0.p95_connect_ms', 400);
    }

    private function createFirebaseTables(): void
    {
        Schema::dropIfExists('firebase_event_app_open');
        Schema::dropIfExists('firebase_event_vpn_session');
        Schema::dropIfExists('firebase_event_vpn_probe_result');
        Schema::dropIfExists('firebase_event_vpn_probe');
        Schema::dropIfExists('firebase_event_common');

        Schema::create('firebase_event_common', function ($table) {
            $table->string('event_id')->primary();
            $table->string('event_name', 64);
            $table->string('app_id', 128)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('device_id', 128)->nullable();
            $table->string('user_id', 128)->nullable();
            $table->string('user_country', 16)->nullable();
            $table->string('user_region', 64)->nullable();
            $table->string('network_type', 32)->nullable();
            $table->string('isp', 128)->nullable();
            $table->string('asn', 32)->nullable();
            $table->bigInteger('event_time_ms')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->integer('duplicate_count')->default(0);
        });

        Schema::create('firebase_event_vpn_session', function ($table) {
            $table->string('event_id')->primary();
            $table->string('session_id', 64)->nullable();
            $table->string('node_id', 128)->nullable();
            $table->string('node_host', 255)->nullable();
            $table->string('node_name', 128)->nullable();
            $table->string('node_country', 16)->nullable();
            $table->string('node_region', 64)->nullable();
            $table->string('protocol', 64)->nullable();
            $table->string('connect_type', 32)->nullable();
            $table->boolean('success')->nullable();
            $table->bigInteger('connect_ms')->nullable();
            $table->bigInteger('duration_ms')->nullable();
            $table->bigInteger('upload_bytes')->nullable();
            $table->bigInteger('download_bytes')->nullable();
            $table->bigInteger('total_bytes')->nullable();
            $table->integer('retry_count')->nullable();
            $table->string('fail_stage', 32)->nullable();
            $table->string('error_stage', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 255)->nullable();
        });

        Schema::create('firebase_event_vpn_probe', function ($table) {
            $table->string('event_id')->primary();
            $table->string('probe_id', 64)->nullable();
            $table->string('probe_type', 64)->nullable();
            $table->string('probe_trigger', 64)->nullable();
        });

        Schema::create('firebase_event_vpn_probe_result', function ($table) {
            $table->id();
            $table->string('event_id', 64);
            $table->integer('result_index');
            $table->string('node_id', 128)->nullable();
            $table->string('node_name', 128)->nullable();
            $table->string('node_country', 16)->nullable();
            $table->string('node_region', 64)->nullable();
            $table->string('protocol', 64)->nullable();
            $table->boolean('success')->nullable();
            $table->bigInteger('latency_ms')->nullable();
            $table->bigInteger('tcp_connect_ms')->nullable();
            $table->bigInteger('tls_hk_ms')->nullable();
            $table->bigInteger('proxy_hk_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->bigInteger('timeout_ms')->nullable();
        });

        Schema::create('firebase_event_app_open', function ($table) {
            $table->string('event_id')->primary();
            $table->string('open_type', 32)->nullable();
            $table->string('install_channel', 64)->nullable();
            $table->bigInteger('launch_ms')->nullable();
        });
    }

    private function seedProbeResult(string $eventId, array $overrides = []): void
    {
        $receivedAt = $overrides['received_at'] ?? '2026-06-29 08:00:00';

        DB::table('firebase_event_common')->insert([
            'event_id' => $eventId,
            'event_name' => 'vpn_probe',
            'app_id' => $overrides['app_id'] ?? 'com.example.vpn',
            'platform' => $overrides['platform'] ?? 'android',
            'app_version' => $overrides['app_version'] ?? '1.0.0',
            'device_id' => $overrides['device_id'] ?? 'device-001',
            'user_id' => $overrides['user_id'] ?? 'user-001',
            'user_country' => $overrides['user_country'] ?? 'SG',
            'user_region' => $overrides['user_region'] ?? 'Singapore',
            'network_type' => $overrides['network_type'] ?? 'wifi',
            'isp' => $overrides['isp'] ?? 'Singtel',
            'asn' => $overrides['asn'] ?? 'AS3758',
            'event_time_ms' => Carbon::parse($receivedAt)->getTimestampMs(),
            'received_at' => $receivedAt,
            'duplicate_count' => 0,
        ]);

        DB::table('firebase_event_vpn_probe')->insert([
            'event_id' => $eventId,
            'probe_id' => $overrides['probe_id'] ?? 'probe-001',
            'probe_type' => $overrides['probe_type'] ?? 'full_probe',
            'probe_trigger' => $overrides['probe_trigger'] ?? 'manual_refresh',
        ]);

        DB::table('firebase_event_vpn_probe_result')->insert([
            'event_id' => $eventId,
            'result_index' => $overrides['result_index'] ?? 0,
            'node_id' => $overrides['node_id'] ?? 'node-sg-01',
            'node_name' => $overrides['node_name'] ?? 'Singapore 01',
            'node_country' => $overrides['node_country'] ?? 'SG',
            'node_region' => $overrides['node_region'] ?? 'Singapore',
            'protocol' => $overrides['protocol'] ?? 'vless_reality',
            'success' => $overrides['success'] ?? 1,
            'latency_ms' => $overrides['latency_ms'] ?? 120,
            'tcp_connect_ms' => $overrides['tcp_connect_ms'] ?? 80,
            'tls_hk_ms' => $overrides['tls_hk_ms'] ?? 90,
            'proxy_hk_ms' => $overrides['proxy_hk_ms'] ?? 100,
            'error_code' => $overrides['error_code'] ?? null,
            'error_message' => $overrides['error_message'] ?? null,
            'timeout_ms' => $overrides['timeout_ms'] ?? 3000,
        ]);
    }

    private function seedSession(string $eventId, array $overrides = []): void
    {
        $receivedAt = $overrides['received_at'] ?? '2026-06-29 08:00:00';

        DB::table('firebase_event_common')->insert([
            'event_id' => $eventId,
            'event_name' => 'vpn_session',
            'app_id' => $overrides['app_id'] ?? 'com.example.vpn',
            'platform' => $overrides['platform'] ?? 'android',
            'app_version' => $overrides['app_version'] ?? '1.0.0',
            'device_id' => $overrides['device_id'] ?? $eventId . '-device',
            'user_id' => $overrides['user_id'] ?? 'user-001',
            'user_country' => $overrides['user_country'] ?? 'SG',
            'user_region' => $overrides['user_region'] ?? 'Singapore',
            'network_type' => $overrides['network_type'] ?? 'wifi',
            'isp' => $overrides['isp'] ?? 'Singtel',
            'asn' => $overrides['asn'] ?? 'AS3758',
            'event_time_ms' => Carbon::parse($receivedAt)->getTimestampMs(),
            'received_at' => $receivedAt,
            'duplicate_count' => 0,
        ]);

        DB::table('firebase_event_vpn_session')->insert([
            'event_id' => $eventId,
            'session_id' => $overrides['session_id'] ?? 'sess-' . $eventId,
            'node_id' => $overrides['node_id'] ?? 'node-sg-01',
            'node_host' => $overrides['node_host'] ?? 'sg01.example.test',
            'node_name' => $overrides['node_name'] ?? 'Singapore 01',
            'node_country' => $overrides['node_country'] ?? 'SG',
            'node_region' => $overrides['node_region'] ?? 'Singapore',
            'protocol' => $overrides['protocol'] ?? 'vless_reality',
            'connect_type' => $overrides['connect_type'] ?? 'auto',
            'success' => $overrides['success'] ?? 1,
            'connect_ms' => $overrides['connect_ms'] ?? 120,
            'duration_ms' => $overrides['duration_ms'] ?? 60000,
            'upload_bytes' => $overrides['upload_bytes'] ?? 512,
            'download_bytes' => $overrides['download_bytes'] ?? 512,
            'total_bytes' => $overrides['total_bytes'] ?? 1024,
            'retry_count' => $overrides['retry_count'] ?? 0,
            'fail_stage' => $overrides['fail_stage'] ?? null,
            'error_stage' => $overrides['error_stage'] ?? null,
            'error_code' => $overrides['error_code'] ?? null,
            'error_message' => $overrides['error_message'] ?? null,
        ]);
    }

    private function adminFirebaseUri(string $action): string
    {
        $suffix = 'firebase-analytics/' . trim($action, '/');

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v3/') && str_ends_with($route->uri(), $suffix)) {
                return '/' . $route->uri();
            }
        }

        return '/api/v3/admin/' . $suffix;
    }
}
