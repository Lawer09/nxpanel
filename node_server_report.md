# 节点实时上报（node_server_report）

## 1. 节点上报内容（来自 `ServerController::report`）

节点调用接口：`POST /api/v2/server/report`

服务端按以下字段处理上报内容：

- `traffic`：流量明细数组，仅接受 `[uid, traffic]` 两元素且都为数字；用于更新在线用户数与流量入账。
- `alive`：存活用户列表；异步投递 `UserAliveSyncJob`。
- `online`：用户实时连接数映射（`uid => conn`）；写入用户连接数缓存。
- `status`：节点负载状态；提取并缓存以下结构：
  - `cpu`
  - `mem.total / mem.used`
  - `swap.total / swap.used`
  - `disk.total / disk.used`
  - `kernel_status`
  - `updated_at`
- `metrics`：节点运行指标（如连接数、吞吐、负载等）；交由 `ServerService::updateMetrics` 处理并写缓存。

### 1.1 上报请求体格式（字段级说明）

说明：以下是当前服务端实际消费格式，未出现的字段会被忽略。

```json
{
  "traffic": [[10001, 123456], [10002, 7890]],
  "alive": [10001, 10002],
  "online": {
    "10001": 2,
    "10002": 1
  },
  "status": {
    "cpu": 0.35,
    "mem": {"total": 16777216, "used": 8388608},
    "swap": {"total": 2097152, "used": 1024},
    "disk": {"total": 1073741824, "used": 536870912},
    "kernel_status": true
  },
  "metrics": {
    "uptime": 86400,
    "active_connections": 123,
    "inbound_speed": 1048576,
    "outbound_speed": 524288
  }
}
```

字段说明：

- `traffic`（array<array>）
  - 期望结构：`[[uid, traffic], ...]`
  - 仅当元素为长度 `2` 且两个值都为数字时才会被采纳。
  - 作用：
    - 有效条数用于更新 `SERVER_{TYPE}_ONLINE_USER_{nodeId}`；
    - 触发 `UserService::trafficFetch(...)` 入账处理；
    - 更新 `SERVER_{TYPE}_LAST_PUSH_AT_{nodeId}`。

- `alive`（array）
  - 节点上报的活跃用户集合。
  - 作用：异步投递 `UserAliveSyncJob`（仅在非空数组时）。

- `online`（object）
  - 期望结构：`{"uid": conn, ...}`。
  - `uid` 作为用户 ID，`conn` 为连接数（会转为整数）。
  - 作用：逐用户写入 `USER_ONLINE_CONN_{type}_{nodeId}_{uid}` 缓存。

- `status`（object）
  - 当前仅消费字段：
    - `cpu`（float）
    - `mem.total` / `mem.used`（int）
    - `swap.total` / `swap.used`（int）
    - `disk.total` / `disk.used`（int）
    - `kernel_status`（mixed，原样透传）
  - 缺失字段会按 `0` 或 `null` 回退。
  - 作用：写入 `LOAD_STATUS` 当前快照、`LAST_LOAD_AT`，并维护最近 1 小时历史。

- `metrics`（object）
  - 由 `ServerService::updateMetrics` 处理并标准化。
  - 当前标准化字段：
    - 数值：`uptime`, `goroutines`, `active_connections`, `tcp_connections`, `total_connections`, `total_users`, `active_users`, `inbound_speed`, `outbound_speed`
    - 对象/数组：`cpu_per_core`, `load`, `speed_limiter`, `gc`, `api`, `ws`, `limits`
    - 其他：`kernel_status`（bool）, `updated_at`（timestamp）
  - 作用：写入 `METRICS` 当前快照并维护最近 1 小时历史。

### 1.2 实时队列快照字段（管理端排查）

`report` 接口会把本次上报原始片段写入 `realtime:node_server_report:latest`，单条结构如下：

- `node_id`（int）
- `node_type`（string）
- `ip`（string|null）
- `traffic`（array）
- `alive`（array）
- `online`（array|object）
- `status`（array|object）
- `metrics`（array|object）
- `created_at`（string，`Y-m-d H:i:s`）

同时会更新节点相关状态缓存：

- `SERVER_{TYPE}_LAST_CHECK_AT_{nodeId}`：最后检查时间。
- `SERVER_{TYPE}_ONLINE_USER_{nodeId}`：在线用户数（来自有效 `traffic`）。
- `SERVER_{TYPE}_LAST_PUSH_AT_{nodeId}`：最后流量推送时间。
- `SERVER_{TYPE}_LOAD_STATUS_{nodeId}` / `SERVER_{TYPE}_LAST_LOAD_AT_{nodeId}`：最近负载状态。
- `SERVER_{TYPE}_LOAD_STATUS_HISTORY_{nodeId}`：负载历史（最近 1 小时窗口）。
- `SERVER_{TYPE}_METRICS_{nodeId}` / `SERVER_{TYPE}_METRICS_HISTORY_{nodeId}`：最近指标及历史。

此外，接口会将原始上报快照写入实时队列缓存（用于管理端排查）：

- 缓存 key：`realtime:node_server_report:latest`
- 保留条数：`500`
- 过期时间：`3600s`
- 单条快照结构：
  - `node_id`
  - `node_type`
  - `ip`
  - `traffic`
  - `alive`
  - `online`
  - `status`
  - `metrics`
  - `created_at`

---

## 2. 实时查看接口

- 方法/路径：`POST /api/v3/admin/report/nodeServer/realtime`
- 请求校验：`App\\Http\\Requests\\Admin\\NodeServerRealtimeRequest`

请求参数：

- `page`：可选，默认 `1`
- `pageSize`：可选，默认 `50`，最大 `200`

请求体示例：

```json
{
  "page": 1,
  "pageSize": 50
}
```

返回字段：

- `data`（实时队列内容分页，按时间倒序）
- `total`
- `page`
- `pageSize`

data格式参考示例
{
  "node_id": 39,
  "node_type": "vless",
  "ip": "162.128.71.76",
  "traffic": {
    "3081": [
      63790,
      2707662
    ],
    "23769": [
      33249,
      7727579
    ]
  },
  "alive": {
    "3081": [
      "39.128.197.69"
    ],
    "23769": [
      "47.11.226.33"
    ]
  },
  "online": {
    "3081": 1,
    "23769": 1
  },
  "status": {
    "cpu": 0.25062656689260776,
    "mem": {
      "total": 8339636224,
      "used": 249847808
    },
    "swap": {
      "total": 0,
      "used": 0
    },
    "disk": {
      "total": 20922114048,
      "used": 1392312320
    },
    "inbound_speed": 1617,
    "outbound_speed": 173920
  },
  "metrics": {
    "uptime": 1037229,
    "goroutines": 205,
    "active_connections": 0,
    "total_connections": 0,
    "tcp_connections": 126,
    "total_users": 24763,
    "active_users": 2,
    "inbound_speed": 1617,
    "outbound_speed": 173920,
    "cpu_per_core": [
      0.09813542699535498,
      0.09810333544504725,
      0.06539153171301824,
      0.01635055578953331
    ],
    "load": [
      0.08,
      0.02,
      0.01
    ],
    "kernel_status": false
  },
  "created_at": "2026-05-08 16:21:50"
}

## 3. 上报数据报表

1. v3_node_server_report_node 上报数据节点信息
    id
    date 根据上报时间
    hour 根据上报时间
    node_id
    node_type  来着节点表数据
    node_host  来着节点表数据
    node_public_ip  节点公网ip来自节点绑定的机器的ip，而不是上报数据返回的ip，因为这个ip是内部ip而不是公网ip

    traffic_upload    累计traffic中下载 单位 b
    traffic_download  累计traffic中上传 单位 b

    avg_cpu_usage   平均cpu使用率 百分比  来自status，根据上报次数平均
    avg_mem_usage   平均内存使用率 百分比 来自status
    max_cpu_usage   最大，百分比  来自status对比
    max_mem_usage   最大，百分比  来自status对比
    avg_disk_usage   百分比，来自status
    avg_inbound_speed 来自status
    avg_outbound_speed 来自status
    max_inbound_speed
    max_outbound_speed
    avg_tcp_connections 来自 metrics
    max_tcp_connections  峰值tcp连接数，来自 metrics
    avg_alive_users
    max_alive_users      峰值活跃用户，来自 metrics
    compute_count   参与计算的数据数

2. v3_node_server_report_user 上报数据节点信息
    id
    date 根据上报时间
    hour 根据上报时间
    user_id 来自traffic中的key
    app_id 来自用户表，根据user_id获取
    app_version 来自用户表
    country 来自用户表
    traffic_upload    累计traffic中对应用户id下载 单位 b
    traffic_download  累计traffic中对应用户id上传 单位 b
    compute_count   参与计算的数据数

---

## 4. Phase 1 已实现流程（2026-05-08）

本阶段已按“节点上报 -> Redis 缓存 -> 定时处理 -> OSS + 队列 -> 队列消费入库”落地，重点如下：

1) 节点上报写入 Redis 原始桶

- 入口：`POST /api/v2/server/report`
- 代码：`app/Http/Controllers/V2/Server/ServerController.php`
- 新增服务：`App\Services\NodeServerReportService::pushRawPayload(...)`
- Redis key：`node_server_report:raw:{yyyyMMddHHmm}`（UTC+8，5 分钟桶）
- TTL：`3600s`

2) 定时任务派发（先归档 OSS，再投递队列）

- 命令：`php artisan node_server_report:dispatch`
- 代码：`app/Console/Commands/DispatchNodeServerReport.php`
- 调度：`app/Console/Kernel.php` 每 5 分钟执行（`onOneServer + withoutOverlapping(5)`）
- 行为：
  - 读取上一 5 分钟桶数据；
  - 归档到 OSS（失败不删 Redis，等待重试）；
  - 按 chunk 分发队列任务；
  - 成功后删除 Redis 桶。
- OSS 路径：`node_server_report/raw/YYYY/MM/DD/HH-mm-ss_xxx.ndjson`

3) 队列消费与入库

- Job：`app/Jobs/ProcessNodeServerReportBatchJob.php`（队列：`stat`）
- Service：`app/Services/NodeServerReportService.php`
- 落库表：
  - `v3_node_server_report_node`
  - `v3_node_server_report_user`
- Migration：`database/migrations/2026_05_08_200000_create_v3_node_server_report_tables.php`

4) traffic 口径（按 A 实施）

- 新格式：`traffic: {uid: [upload, download]}` -> 分别写入 upload/download。
- 旧格式：`traffic: [[uid, traffic], ...]` -> 记为 `download=traffic, upload=0`。

5) 管理端查询接口（Phase 1 基础版）

- `POST /api/v3/admin/report/nodeServerReport/node/query`
  - Request：`NodeServerReportNodeQueryRequest`
  - 查询表：`v3_node_server_report_node`
- `POST /api/v3/admin/report/nodeServerReport/user/query`
  - Request：`NodeServerReportUserQueryRequest`
  - 查询表：`v3_node_server_report_user`
- 路由：`app/Http/Routes/V3/AdminRoute.php`
- 控制器：`app/Http/Controllers/V3/Admin/ReportController.php`

6) 当前已知说明

- CPU 字段当前按上报原值入库（未强制做 0-1/0-100 归一）；
- `node_public_ip` 取节点绑定机器主 IP（优先 `ip_machine + v2_ip_pool.ip`，其次 `machines.ip_address`）；
- 为保证幂等，当前采用“按维度先查后累加更新”的方式合并。
