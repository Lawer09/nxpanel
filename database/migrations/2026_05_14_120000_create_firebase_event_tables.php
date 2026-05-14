<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('firebase_event_common')) {
            DB::statement("CREATE TABLE IF NOT EXISTS firebase_event_common (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键，仅用于数据库内部关联与分页',
              event_id VARCHAR(64) NOT NULL COMMENT '客户端生成的唯一事件 ID，例如 evt_{ULID}',
              app_id VARCHAR(128) NOT NULL COMMENT 'App 包名或业务应用 ID，对应客户端公共字段 app_id',
              firestore_path VARCHAR(255) DEFAULT NULL COMMENT 'Firebase Firestore 文档路径，例如 apps/{appId}/events/{eventId}',
              event_name VARCHAR(64) NOT NULL COMMENT '事件名称：app_open、vpn_probe、vpn_session、server_api_error',
              platform VARCHAR(32) NOT NULL COMMENT '客户端平台，例如 android、ios',
              app_version VARCHAR(64) NOT NULL COMMENT 'App 版本号',
              device_id VARCHAR(128) NOT NULL COMMENT '设备唯一标识',
              user_id VARCHAR(128) DEFAULT NULL COMMENT '用户 ID，未登录时可为空',
              user_country VARCHAR(16) DEFAULT NULL COMMENT '用户国家或地区 ISO 代码',
              user_region VARCHAR(64) DEFAULT NULL COMMENT '用户所在地区、省份或城市',
              language VARCHAR(32) DEFAULT NULL COMMENT '客户端系统语言',
              network_type VARCHAR(32) DEFAULT NULL COMMENT '客户端网络类型，例如 wifi、cellular',
              isp VARCHAR(128) DEFAULT NULL COMMENT '用户网络运营商',
              asn VARCHAR(32) DEFAULT NULL COMMENT '用户网络 ASN',
              event_time_ms BIGINT DEFAULT NULL COMMENT '事件发生时间，毫秒时间戳',
              created_at_ms BIGINT DEFAULT NULL COMMENT '客户端写入或创建时间，毫秒时间戳',
              firebase_event_id VARCHAR(128) DEFAULT NULL COMMENT 'Firebase 转发层传入的事件 ID，通常来自请求头 X-Event-Id 或 Firebase 文档 ID',
              firebase_event_time VARCHAR(64) DEFAULT NULL COMMENT 'Firebase 转发层传入的事件时间，保留原始字符串',
              firebase_source VARCHAR(255) DEFAULT NULL COMMENT 'Firebase 转发来源标识，例如 Firestore trigger 或函数名称',
              forwarded_at VARCHAR(64) DEFAULT NULL COMMENT 'Firebase 函数转发到本服务的时间，保留原始字符串',
              received_at DATETIME(3) NOT NULL COMMENT 'Go 服务接收到请求并写入队列的时间',
              duplicate_count INT NOT NULL DEFAULT 0 COMMENT '重复接收次数，同一 event_id 再次入库时递增',
              last_duplicate_at DATETIME(3) DEFAULT NULL COMMENT '最近一次重复接收时间',
              PRIMARY KEY (id),
              UNIQUE KEY uk_event_id (event_id),
              KEY idx_app_event_time (app_id,event_name,event_time_ms),
              KEY idx_device_time (device_id,event_time_ms),
              KEY idx_received_at (received_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Firebase 事件公共字段表，每个 event_id 一行，不存储完整原始 JSON';");
        }

        if (!Schema::hasTable('firebase_event_app_open')) {
            DB::statement("CREATE TABLE IF NOT EXISTS firebase_event_app_open (
              event_id VARCHAR(64) NOT NULL COMMENT '事件 ID，关联 firebase_event_common.event_id',
              open_type VARCHAR(32) DEFAULT NULL COMMENT 'App 打开类型：cold_start、hot_start、foreground、push_open、deeplink_open、unknown',
              install_channel VARCHAR(64) DEFAULT NULL COMMENT '安装渠道，例如 google_play、app_store 或其他渠道',
              launch_ms BIGINT DEFAULT NULL COMMENT 'App 启动耗时，单位毫秒',
              PRIMARY KEY (event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='app_open 事件扩展表';");
        }

        if (!Schema::hasTable('firebase_event_vpn_session')) {
            DB::statement("CREATE TABLE IF NOT EXISTS firebase_event_vpn_session (
              event_id VARCHAR(64) NOT NULL COMMENT '事件 ID，关联 firebase_event_common.event_id',
              session_id VARCHAR(64) DEFAULT NULL COMMENT '一次 VPN 连接会话 ID，例如 sess_{ULID}',
              node_id VARCHAR(128) DEFAULT NULL COMMENT '节点 ID，node_id 与 node_host 至少应有一个',
              node_host VARCHAR(255) DEFAULT NULL COMMENT '节点 host，node_id 与 node_host 至少应有一个',
              node_name VARCHAR(128) DEFAULT NULL COMMENT '节点展示名称',
              node_country VARCHAR(16) DEFAULT NULL COMMENT '节点国家或地区 ISO 代码',
              node_region VARCHAR(64) DEFAULT NULL COMMENT '节点地区或城市',
              protocol VARCHAR(64) DEFAULT NULL COMMENT '连接协议，例如 vless_reality',
              connect_type VARCHAR(32) DEFAULT NULL COMMENT '连接方式：auto、manual、retry、fallback、reconnect、unknown',
              success TINYINT(1) DEFAULT NULL COMMENT '本次 VPN 会话是否连接成功，1=成功，0=失败',
              started_at_ms BIGINT DEFAULT NULL COMMENT '用户开始连接时间，毫秒时间戳',
              connected_at_ms BIGINT DEFAULT NULL COMMENT '连接成功时间，失败可为空，毫秒时间戳',
              disconnected_at_ms BIGINT DEFAULT NULL COMMENT '断开时间，失败可为空，毫秒时间戳',
              connect_ms BIGINT DEFAULT NULL COMMENT '从开始连接到成功或失败的耗时，单位毫秒',
              duration_ms BIGINT DEFAULT NULL COMMENT '成功连接后的使用时长，失败通常为 0，单位毫秒',
              upload_bytes BIGINT DEFAULT NULL COMMENT '本次会话累计上传流量，单位 bytes',
              download_bytes BIGINT DEFAULT NULL COMMENT '本次会话累计下载流量，单位 bytes',
              total_bytes BIGINT DEFAULT NULL COMMENT '本次会话累计总流量，单位 bytes',
              disconnect_reason VARCHAR(64) DEFAULT NULL COMMENT '断开原因，失败场景可为空',
              fail_stage VARCHAR(32) DEFAULT NULL COMMENT '失败阶段：start、connect、disconnect',
              error_stage VARCHAR(64) DEFAULT NULL COMMENT '错误阶段：dns、tcp_connect、tls_handshake、proxy_handshake、auth、timeout 等',
              error_code VARCHAR(64) DEFAULT NULL COMMENT '统一错误码，例如 DNS_FAILED、TCP_TIMEOUT、PROXY_REJECTED、UNKNOWN_ERROR',
              error_message VARCHAR(255) DEFAULT NULL COMMENT '错误信息，建议客户端控制在 100 字符内，数据库保留 255 字符',
              tcp_connect_ms BIGINT DEFAULT NULL COMMENT 'TCP 连接耗时，单位毫秒',
              tls_hk_ms BIGINT DEFAULT NULL COMMENT 'TLS 握手耗时，单位毫秒',
              proxy_hk_ms BIGINT DEFAULT NULL COMMENT '代理协议握手耗时，单位毫秒',
              retry_count INT DEFAULT NULL COMMENT '本次连接前或连接中的重试次数',
              PRIMARY KEY (event_id),
              KEY idx_session_id (session_id),
              KEY idx_node_success (node_id, success),
              KEY idx_error (error_stage,error_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='vpn_session 事件扩展表，记录 VPN 连接建立、使用和结束情况';");
        }

        if (!Schema::hasTable('firebase_event_vpn_probe')) {
            DB::statement("CREATE TABLE IF NOT EXISTS firebase_event_vpn_probe (
              event_id VARCHAR(64) NOT NULL COMMENT '事件 ID，关联 firebase_event_common.event_id',
              probe_id VARCHAR(64) DEFAULT NULL COMMENT '一次批量测速 ID，例如 prob_{ULID}',
              probe_type VARCHAR(64) DEFAULT NULL COMMENT '测试类型：latency、tcp_connect、tls_handshake、proxy_handshake、http_ping、full_probe、unknown',
              probe_trigger VARCHAR(64) DEFAULT NULL COMMENT '测速触发场景：app_open、node_list_open、before_connect、manual_refresh、background_check 等',
              node_count INT DEFAULT NULL COMMENT '本次测试节点数量',
              success_count INT DEFAULT NULL COMMENT '成功节点数量',
              fail_count INT DEFAULT NULL COMMENT '失败节点数量',
              duration_ms BIGINT DEFAULT NULL COMMENT '本次批量测速总耗时，单位毫秒',
              batch_index INT DEFAULT NULL COMMENT '分批上报时当前批次，从 1 开始',
              batch_total INT DEFAULT NULL COMMENT '分批上报总批次数',
              PRIMARY KEY (event_id),
              KEY idx_probe_id (probe_id),
              KEY idx_probe_type (probe_type,probe_trigger)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='vpn_probe 事件主表，记录一次批量测速的汇总信息';");
        }

        if (!Schema::hasTable('firebase_event_vpn_probe_result')) {
            DB::statement("CREATE TABLE IF NOT EXISTS firebase_event_vpn_probe_result (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增主键',
              event_id VARCHAR(64) NOT NULL COMMENT '所属 vpn_probe 事件 ID，关联 firebase_event_vpn_probe.event_id',
              result_index INT NOT NULL COMMENT '节点测试结果在 results 数组中的序号，从 0 开始',
              node_id VARCHAR(128) DEFAULT NULL COMMENT '节点 ID',
              node_name VARCHAR(128) DEFAULT NULL COMMENT '节点展示名称',
              node_country VARCHAR(16) DEFAULT NULL COMMENT '节点国家或地区 ISO 代码',
              node_region VARCHAR(64) DEFAULT NULL COMMENT '节点地区或城市',
              protocol VARCHAR(64) DEFAULT NULL COMMENT '测试使用的协议，例如 vless_reality',
              success TINYINT(1) DEFAULT NULL COMMENT '当前节点测试是否成功，1=成功，0=失败',
              latency_ms BIGINT DEFAULT NULL COMMENT '节点延迟，单位毫秒',
              tcp_connect_ms BIGINT DEFAULT NULL COMMENT 'TCP 连接耗时，单位毫秒',
              tls_hk_ms BIGINT DEFAULT NULL COMMENT 'TLS 握手耗时，单位毫秒',
              proxy_hk_ms BIGINT DEFAULT NULL COMMENT '代理协议握手耗时，单位毫秒',
              error_code VARCHAR(64) DEFAULT NULL COMMENT '失败错误码，成功时通常为 NONE 或空',
              error_message VARCHAR(255) DEFAULT NULL COMMENT '失败原因，建议客户端控制在 100 字符内，数据库保留 255 字符',
              timeout_ms BIGINT DEFAULT NULL COMMENT '当前节点测试超时时间，单位毫秒',
              PRIMARY KEY (id),
              UNIQUE KEY uk_event_result (event_id,result_index),
              KEY idx_node_success_latency (node_id,success,latency_ms),
              KEY idx_result_error (error_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='vpn_probe 节点结果明细表，一次测速事件可对应多行结果';");
        }

        if (!Schema::hasTable('firebase_event_server_api_error')) {
            DB::statement("CREATE TABLE IF NOT EXISTS firebase_event_server_api_error (
              event_id VARCHAR(64) NOT NULL COMMENT '事件 ID，关联 firebase_event_common.event_id',
              request_id VARCHAR(64) DEFAULT NULL COMMENT '请求 ID，服务端返回；不存在时客户端生成 req_{ULID}',
              api_path VARCHAR(255) DEFAULT NULL COMMENT 'API 路径，不建议带 query 参数',
              api_domain VARCHAR(255) DEFAULT NULL COMMENT '请求域名',
              http_method VARCHAR(16) DEFAULT NULL COMMENT 'HTTP 请求方法，例如 GET、POST',
              http_status INT DEFAULT NULL COMMENT 'HTTP 状态码，网络层失败可为空',
              business_code VARCHAR(64) DEFAULT NULL COMMENT '服务端业务错误码',
              error_stage VARCHAR(64) DEFAULT NULL COMMENT '错误发生阶段：dns、tcp_connect、http_response、business_response、timeout 等',
              error_code VARCHAR(64) DEFAULT NULL COMMENT '客户端统一错误码，例如 REQUEST_TIMEOUT、SERVER_5XX、BUSINESS_ERROR',
              error_message VARCHAR(255) DEFAULT NULL COMMENT '错误信息，建议客户端控制在 100 字符内，数据库保留 255 字符',
              duration_ms BIGINT DEFAULT NULL COMMENT 'API 请求耗时，单位毫秒',
              trace_id VARCHAR(128) DEFAULT NULL COMMENT '链路追踪 ID',
              retry_count INT DEFAULT NULL COMMENT '当前请求重试次数',
              server_region VARCHAR(64) DEFAULT NULL COMMENT '服务端区域',
              PRIMARY KEY (event_id),
              KEY idx_api_error (api_domain,api_path,error_code),
              KEY idx_trace_id (trace_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='server_api_error 事件扩展表，记录客户端请求服务端 API 失败情况';");
        }

        if (Schema::hasTable('firebase_event_common')) {
            if (!$this->indexExists('firebase_event_common', 'idx_common_filter')) {
                Schema::table('firebase_event_common', function (Blueprint $table) {
                    $table->index(['received_at', 'app_id', 'platform', 'app_version', 'user_country', 'network_type'], 'idx_common_filter');
                });
            }

            if (!$this->indexExists('firebase_event_common', 'idx_common_event_name_received')) {
                Schema::table('firebase_event_common', function (Blueprint $table) {
                    $table->index(['event_name', 'received_at'], 'idx_common_event_name_received');
                });
            }
        }

        if (Schema::hasTable('firebase_event_vpn_session')) {
            if (!$this->indexExists('firebase_event_vpn_session', 'idx_vpn_success_connect')) {
                Schema::table('firebase_event_vpn_session', function (Blueprint $table) {
                    $table->index(['success', 'connect_ms'], 'idx_vpn_success_connect');
                });
            }
        }

        if (Schema::hasTable('firebase_event_server_api_error')) {
            if (!$this->indexExists('firebase_event_server_api_error', 'idx_api_status_error')) {
                Schema::table('firebase_event_server_api_error', function (Blueprint $table) {
                    $table->index(['http_status', 'error_stage', 'error_code'], 'idx_api_status_error');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('firebase_event_server_api_error');
        Schema::dropIfExists('firebase_event_vpn_probe_result');
        Schema::dropIfExists('firebase_event_vpn_probe');
        Schema::dropIfExists('firebase_event_vpn_session');
        Schema::dropIfExists('firebase_event_app_open');
        Schema::dropIfExists('firebase_event_common');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $result = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $indexName]
        );

        return $result !== null;
    }
};
