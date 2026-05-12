# 节点上报数据来源与字段梳理（server / node）
## 1. 目标范围
本次仅梳理：
- 节点自身上报（server -> panel）：`ServerController::report`
- 用户侧节点性能上报（user -> panel）：`UserReportController::batchReport`
- 上报聚合任务：`AggregatePerformanceReports`
- 当前系统可查询/消费到的主要字段（缓存 + 数据库）
---
## 2. 数据来源总览（按链路）
### A. 节点自身上报链路（server/report）
- 路由：`POST /api/v2/server/report`
  - 路由定义：`app/Http/Routes/V2/ServerRoute.php:20`
- 控制器：`app/Http/Controllers/V2/Server/ServerController.php:49`
- 中间件鉴权与节点识别：
  - `app/Http/Middleware/Server.php:16`
  - 校验请求字段：`token`, `node_id`, `node_type`
  - 注入 `node_info` 到 request attributes（供 controller 使用）
该接口接收并处理 5 类上报块：
1) `traffic`
2) `alive`
3) `online`
4) `status`
5) `metrics`
---
### B. 用户侧节点性能上报链路（user/performance/batchReport）
- 路由：`POST /api/v3/user/performance/batchReport`
  - `app/Http/Routes/V3/UserRoute.php:30`
- 控制器：`app/Http/Controllers/V3/User/UserReportController.php:45`
- 请求校验：`app/Http/Requests/User/PerformanceBatchReport.php:9`
- 入 Redis 缓冲：`app/Services/NodePerformanceService.php:67`
- 定时聚合任务（每5分钟）：`perf:aggregate`
  - 定时注册：`app/Console/Kernel.php:58`
  - 聚合实现：`app/Console/Commands/AggregatePerformanceReports.php:29`
---
### C. 管理侧实时查看来源
- 用户实时上报缓存：`realtime:user_report:latest`
  - 写入：`app/Http/Controllers/V3/User/UserReportController.php:58`
  - 读取接口：`GET /admin/userReport/realtime`
    - `app/Http/Controllers/V3/Admin/UserReportController.php:17`
- 节点实时状态（load/metrics/history）来自缓存：
  - 读取聚合接口：`Server` 模型访问器
    - `app/Models/Server.php:417`（last_check_at）
    - `app/Models/Server.php:431`（last_push_at）
    - `app/Models/Server.php:560`（load_status）
    - `app/Models/Server.php:512`（metrics_history）
  - 管理接口（节点历史）：
    - `app/Http/Controllers/V3/Admin/Server/ManageController.php:54`
---
## 3. 字段梳理：节点自身上报（ServerController::report）
来源文件：`app/Http/Controllers/V2/Server/ServerController.php:49`
### 3.1 请求顶层字段
- `traffic`: array
- `alive`: array
- `online`: array
- `status`: object
- `metrics`: object
---
### 3.2 `traffic` 字段（节点流量明细）
代码校验逻辑：
- `traffic` 必须是数组，且每个元素是长度=2的数组
- 两个值都必须是 numeric
- 位置语义：`[user_id, traffic]`（由 `UserService::trafficFetch` 下游消费）
处理结果：
- 更新缓存：
  - `SERVER_{TYPE}_ONLINE_USER_{nodeId}` = `count(traffic有效项)`
  - `SERVER_{TYPE}_LAST_PUSH_AT_{nodeId}` = `time()`
- 调用：`UserService::trafficFetch($node, $nodeType, $data)`
---
### 3.3 `alive` 字段（存活/在线明细）
- 类型：array
- 非空时异步投递：`UserAliveSyncJob::dispatch($alive, $nodeType, $nodeId)`
---
### 3.4 `online` 字段（用户在线连接数）
- 类型：object/map
- 结构：`{ uid: connCount, ... }`
- 对每个 uid 写缓存：
  - key: `USER_ONLINE_CONN_{nodeType}_{nodeId}_{uid}`
  - value: `(int)connCount`
---
### 3.5 `status` 字段（节点负载状态）
结构（按代码解包）：
- `cpu`: float
- `mem.total`: int
- `mem.used`: int
- `swap.total`: int
- `swap.used`: int
- `disk.total`: int
- `disk.used`: int
- `kernel_status`: mixed(nullable)
服务端补充字段：
- `updated_at`: timestamp(now)
写入缓存：
- `SERVER_{TYPE}_LOAD_STATUS_{nodeId}` => statusData
- `SERVER_{TYPE}_LAST_LOAD_AT_{nodeId}` => now timestamp
- `SERVER_{TYPE}_LOAD_STATUS_HISTORY_{nodeId}` => 最近1小时历史数组
---
### 3.6 `metrics` 字段（节点运行指标）
来源：
- `ServerController::report` 调用 `ServerService::updateMetrics`
最终标准化字段（`ServerService::updateMetrics`）：
- `uptime`: int
- `goroutines`: int
- `active_connections`: int
- `tcp_connections`: int
- `total_connections`: int
- `total_users`: int
- `active_users`: int
- `inbound_speed`: int
- `outbound_speed`: int
- `cpu_per_core`: array
- `load`: array
- `speed_limiter`: array
- `gc`: array
- `api`: array
- `ws`: array
- `limits`: array
- `kernel_status`: bool
- `updated_at`: timestamp
写入缓存：
- `SERVER_{TYPE}_METRICS_{nodeId}`
- `SERVER_{TYPE}_METRICS_HISTORY_{nodeId}`（最近1小时）
---
## 4. 字段梳理：用户性能上报（UserReportController + PerformanceBatchReport）
### 4.1 接口与校验
- 控制器：`app/Http/Controllers/V3/User/UserReportController.php:45`
- 表单校验：`app/Http/Requests/User/PerformanceBatchReport.php:9`
请求结构：
#### A) `reports`（数组，最多100条）
每条可含：
- `node_id`: integer nullable
- `node_ip`: string(<=255) nullable
- `vpn_node_ip`: string(<=255) nullable
- `delay`: integer required
- `success_rate`: integer required, 0~100
- `status`: enum nullable (`success|failed|timeout|cancelled`)
- `probe_stage`: enum nullable (`node_connect|tunnel_establish|post_connect_probe`)
- `error_code`: string(<=64) nullable
- `vpn_user_time`: nullable（支持数字或文本，聚合时解析为秒）
- `vpn_user_traffic`: nullable（支持数字或带单位文本，聚合时统一为MB）
- `arise_timestamp`: integer nullable（使用结束时间）
#### B) `metadata`（required object）
- `app_id`: string required
- `app_version`: string nullable
- `platform`: string nullable
- `brand`: string nullable
- `country`: string(2) nullable
- `city`: string nullable
- `isp`: string nullable
- `timestamp`: integer required
- `connect_country`: string(2) nullable
#### C) `user_default`
- 控制器透传字段，校验层未定义子字段（原样进入缓存 payload）
---
### 4.2 缓冲层 payload（Redis）
来源：`NodePerformanceService::batchReportPerformance`  
写入 key：`perf:raw:{YmdHi}`（5分钟桶）
payload 字段：
- `metadata`
- `reports`
- `user_default`
- `userId`
- `clientIp`
- `reported_at`（优先 metadata.timestamp）
- `created_at`
---
## 5. 字段梳理：聚合任务（AggregatePerformanceReports）
来源：`app/Console/Commands/AggregatePerformanceReports.php`
### 5.1 flatten 后统一记录字段（rawRecords）
每条记录统一为（兼容空 reports 与旧格式）：
- `user_id`
- `node_id`
- `node_ip`（含 node_ip/vpn_node_ip 归一化）
- `delay`（负值修正为0）
- `success_rate`
- `client_ip`
- `client_country`
- `client_city`
- `client_isp`
- `platform`
- `brand`
- `app_id`
- `app_version`
- `connect_country`
- `status`（标准化）
- `probe_stage`（`tunnel_establish` 归并为 `node_connect`）
- `error_code`（success时强制null）
- `vpn_user_time_seconds`（由 vpn_user_time 解析）
- `vpn_user_traffic_mb`（由 vpn_user_traffic 解析）
- `event_timestamp_ms`（优先 metadata.timestamp）
- `arise_timestamp_ms`
- `reported_at`
- `created_at`
补充规则：
- 仅有 `node_ip` 且 `node_id<=0` 时，尝试映射内部节点ID（`v2_server.host`）
- 映射缓存：`perf:node_ip_to_id:{md5(node_ip)}`
---
### 5.2 写入表：`v2_node_performance_aggregated`
维度字段：
- `date`,`hour`,`minute`
- `node_id`
- `client_country`
- `platform`
- `client_isp`
- `app_id`
- `app_version`
指标字段：
- `avg_success_rate`（加权）
- `avg_delay`（加权）
- `total_count`
---
### 5.3 写入表：`v2_node_probe_aggregated`（节点错误排查核心）
维度字段：
- `date`,`hour`,`minute`
- `node_id`,`node_ip`
- `client_country`,`platform`,`client_isp`
- `app_id`,`app_version`
- `probe_stage`
- `status`
- `error_code`
- `dimension_hash`（唯一）
指标字段：
- `total_count`
---
### 5.4 写入表：`v2_node_traffic_aggregated`（用户上报流量分析）
维度字段：
- `date`,`hour`,`minute`（优先 arise_timestamp 归桶）
- `node_id`,`node_ip`
- `client_country`,`platform`,`client_isp`
- `app_id`,`app_version`
- `dimension_hash`（唯一）
指标字段：
- `total_usage_seconds`
- `total_usage_mb`
- `report_count`
---
### 5.5 写入表：`v3_user_report_count`（用户上报次数）
口径：
- 每个 payload 计 1 次（不是 reports 条数）
字段：
- `date`,`hour`,`minute`
- `user_id`
- `report_count`
- `node_count`（该 payload 涉及的去重 node_id 数）
- `client_country`,`client_isp`
- `platform`,`app_id`,`app_version`
---
## 6. 当前系统“节点问题排查”相关字段清单（可直接用于排查）
### 6.1 实时缓存类（节点在线/状态）
- `SERVER_{TYPE}_LAST_CHECK_AT_{nodeId}`：最近握手/上报检查时间
- `SERVER_{TYPE}_LAST_PUSH_AT_{nodeId}`：最近traffic push时间
- `SERVER_{TYPE}_ONLINE_USER_{nodeId}`：在线用户数（来自traffic计数）
- `SERVER_{TYPE}_LOAD_STATUS_{nodeId}`：当前负载
- `SERVER_{TYPE}_LOAD_STATUS_HISTORY_{nodeId}`：最近1小时负载历史
- `SERVER_{TYPE}_METRICS_{nodeId}`：当前运行指标
- `SERVER_{TYPE}_METRICS_HISTORY_{nodeId}`：最近1小时指标历史
- `USER_ONLINE_CONN_{nodeType}_{nodeId}_{uid}`：用户在该节点连接数
---
### 6.2 错误排查聚合类（推荐）
`v2_node_probe_aggregated` 关键字段：
- 节点定位：`node_id`,`node_ip`
- 失败语义：`probe_stage`,`status`,`error_code`
- 客户端侧切片：`client_country`,`platform`,`client_isp`,`app_id`,`app_version`
- 量级：`total_count`
- 时间：`date`,`hour`,`minute`
---
### 6.3 节点使用量排查类
`v2_node_traffic_aggregated` 关键字段：
- 节点：`node_id`,`node_ip`
- 使用指标：`total_usage_seconds`,`total_usage_mb`,`report_count`
- 客户端切片：`client_country`,`platform`,`client_isp`,`app_id`,`app_version`
- 时间：`date`,`hour`,`minute`
---
## 7. 备注（实现层差异与兼容）
- `V3\User\UserReportController::report`（单条上报）已返回错误，当前推荐/实际使用 `batchReport`。
- `probe_stage` 入参允许 `tunnel_establish`，聚合时标准化为 `node_connect`。
- 若客户端不上报 `status` 但上报了 `error_code`，聚合按失败处理（`status=failed`）。
- 外部节点可仅用 `node_ip` 上报，系统会尝试映射到内部 `node_id`，未映射时保持 `node_id=0`。
