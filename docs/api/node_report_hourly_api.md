# node_report API 文档

本文档参照 `node_server_report_api.md` 的结构，定义节点报表（`node_report`）统一查询接口与字段映射。

数据来源（按小时聚合后写入 `v3_report_node_hourly`）：

- `v3_node_server_report_node`
- `v3_user_report_node`（原 `v3_user_report_node_summary`）

---

## 1. 节点报表查询

- 方法/路径：`POST /api/v3/admin/report/nodeReport/query`
- 说明：查询统一节点报表（节点维度）。

### 1.1 请求参数

- `dateFrom/dateTo` `string|null`
- `hourFrom/hourTo` `int|null`，范围 `0-23`
- `groupBy` `string[]|null`，可选：
  - `date` `hour` `node_id` `node_type` `node_host` `node_public_ip` `probe_stage` `app_id` `app_version`
- `filters` `object|null`
  - `filters.nodeIds` `int[]|null`
  - `filters.nodeTypes` `string[]|null`
  - `filters.nodeHosts` `string[]|null`
  - `filters.nodePublicIps` `string[]|null`
  - `filters.probeStages` `string[]|null`
  - `filters.appIds` `string[]|null`
  - `filters.appVersions` `string[]|null`
- `page/pageSize` `int|null`（`pageSize` 最大 `200`）
- `orderBy` `string|null`
- `orderDirection` `asc|desc|null`

说明：

- `probe_stage` 默认值为 `post_connect_probe`。

请求示例：

```json
{
  "dateFrom": "2026-05-08",
  "dateTo": "2026-05-08",
  "hourFrom": 0,
  "hourTo": 23,
  "groupBy": ["date", "hour", "node_id", "probe_stage"],
  "filters": {
    "nodeIds": [1001, 1002],
    "probeStages": ["post_connect_probe"]
  },
  "page": 1,
  "pageSize": 50,
  "orderBy": "report_count_node",
  "orderDirection": "desc"
}
```

### 1.2 返回字段

顶层：

- `data` `array<object>`（已 camelCase）
- `total` `int`
- `page` `int`
- `pageSize` `int`
- `dateFrom/dateTo` `string`
- `hourFrom/hourTo` `int|null`
- `groupBy` `string[]`

`data[]`：

- 维度字段：`date/hour/nodeId/nodeType/nodeHost/nodePublicIp/probeStage/appId/appVersion`
- 指标字段：
  - `trafficUpload` `number`（KB，节点上报原始单位 B，按 `/1024` 转换）
  - `trafficDownload` `number`（KB，节点上报原始单位 B，按 `/1024` 转换）
  - `avgCpuUsage` `number`
  - `avgMemUsage` `number`
  - `maxCpuUsage` `number`
  - `maxMemUsage` `number`
  - `avgDiskUsage` `number`
  - `avgInboundSpeed` `number`
  - `avgOutboundSpeed` `number`
  - `maxInboundSpeed` `number`
  - `maxOutboundSpeed` `number`
  - `avgTcpConnections` `number`
  - `maxTcpConnections` `number`
  - `avgAliveUsers` `number`
  - `maxAliveUsers` `number`
  - `avgDelay` `number`
  - `trafficUsage` `number`（KB，用户上报原始单位 MB，按 `*1024` 转换）
  - `trafficUseTime` `int`
  - `successCount` `int`
  - `failCount` `int`
  - `successRate` `number`
  - `reportCountNode` `int`
  - `reportCountUser` `int`

---

## 2. 前端字段映射表

| 前端字段 | 后端字段(DB) | 说明 |
|---|---|---|
| date | date | 日期（UTC+8） |
| hour | hour | 小时（0-23） |
| nodeId | node_id | 节点 ID |
| nodeType | node_type | 节点类型 |
| nodeHost | node_host | 节点 host |
| nodePublicIp | node_public_ip | 节点公网 IP |
| probeStage | probe_stage | 探测阶段，默认 `post_connect_probe` |
| appId | app_id | 应用 ID |
| appVersion | app_version | 应用版本 |
| trafficUpload | traffic_upload | 上传流量（KB，节点上报原始单位 B，按 `/1024` 转换） |
| trafficDownload | traffic_download | 下载流量（KB，节点上报原始单位 B，按 `/1024` 转换） |
| avgCpuUsage | avg_cpu_usage | 平均 CPU 使用率 |
| avgMemUsage | avg_mem_usage | 平均内存使用率（%） |
| maxCpuUsage | max_cpu_usage | CPU 峰值 |
| maxMemUsage | max_mem_usage | 内存峰值（%） |
| avgDiskUsage | avg_disk_usage | 平均磁盘使用率（%） |
| avgInboundSpeed | avg_inbound_speed | 平均入站速率 |
| avgOutboundSpeed | avg_outbound_speed | 平均出站速率 |
| maxInboundSpeed | max_inbound_speed | 入站峰值 |
| maxOutboundSpeed | max_outbound_speed | 出站峰值 |
| avgTcpConnections | avg_tcp_connections | 平均 TCP 连接数 |
| maxTcpConnections | max_tcp_connections | TCP 连接峰值 |
| avgAliveUsers | avg_alive_users | 平均活跃用户数 |
| maxAliveUsers | max_alive_users | 活跃用户峰值 |
| avgDelay | avg_delay | 平均延迟 |
| trafficUsage | traffic_usage | 用户上报流量（KB，用户上报原始单位 MB，按 `*1024` 转换） |
| trafficUseTime | traffic_use_time | 用户上报使用时长（秒） |
| successCount | success_count | 成功数 |
| failCount | fail_count | 失败数 |
| successRate | success_rate | 成功率（%） |
| reportCountNode | report_count_node | 节点侧样本数 |
| reportCountUser | report_count_user | 用户侧样本数 |

---

## 3. 数据口径说明

- 节点指标（CPU/内存/连接数/在线数/上下行速率）来自 `v3_node_server_report_node`。
- 业务指标（延迟/成功失败/流量使用）来自 `v3_user_report_node`。
- 两张源表与 `v3_report_node_hourly` 均已按 `app_id`/`app_version` 拆分维度，服务端聚合时系统指标重复存储（同一节点下各 app 行系统值相同）。
- `report_count_node` 与 `report_count_user` 分别表示两侧样本计数，不做字段合并。
- 报表流量字段统一为 `KB`。
- 节点上报流量原始单位为 `B`，查询层按 `value / 1024` 转换为 `KB`。
- 用户上报流量原始单位为 `MB`，查询层按 `value * 1024` 转换为 `KB`。

---

## 4. 任务命令

1. 小时聚合（重算指定小时）

```bash
php artisan report_hourly:aggregate --date=2026-05-09 --hour=10 --rebuild
```

2. 小时对账（源表 vs 小时报表）

```bash
php artisan report_hourly:reconcile 2026-05-09 --hour=10
```

3. 天级重建（重新聚合整天 24 小时）

```bash
php artisan report_hourly:rebuild 2026-05-09
```

补充：仅重建某小时可加 `--hour=10`；保留已有数据并执行 upsert 可加 `--keep-existing`。

### 4.1 OSS 回放（存量重建）

```bash
# 回放 node 端（v3_node_server_report_node）
php artisan node_server_report:replay-oss 2026-05-01 --clear-day

# 回放 user 端（v3_user_report_node）
php artisan user_report:replay-oss 2026-05-01 --clear-day

# 重建小时表
php artisan report_hourly:rebuild 2026-05-01
```
