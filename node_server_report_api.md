# node_server_report API 文档

本文档基于当前代码实现，整理节点上报实时查询与报表查询接口，并附前端字段映射表。

## 1. 实时队列查询

- 方法/路径：`POST /api/v3/admin/report/nodeServer/realtime`
- 控制器：`App\Http\Controllers\V3\Admin\ReportController::nodeServerRealtime`
- Request：`App\Http\Requests\Admin\NodeServerRealtimeRequest`

### 1.1 请求参数

- `page` `int|null`，默认 `1`
- `pageSize` `int|null`，默认 `50`，范围 `1-200`

请求示例：

```json
{
  "page": 1,
  "pageSize": 50
}
```

### 1.2 返回字段

顶层：

- `data` `array<object>`
- `total` `int`
- `page` `int`
- `pageSize` `int`

`data[]`（实时快照，保持 snake_case）：

- `node_id` `int`
- `node_type` `string`
- `ip` `string|null`
- `traffic` `array|object`
- `alive` `array|object`
- `online` `array|object`
- `status` `array|object`
- `metrics` `array|object`
- `created_at` `string`

---

## 2. 节点报表查询（node 维度）

- 方法/路径：`POST /api/v3/admin/report/nodeServerReport/node/query`
- 控制器：`App\Http\Controllers\V3\Admin\ReportController::queryNodeServerReportNode`
- Request：`App\Http\Requests\Admin\NodeServerReportNodeQueryRequest`

### 2.1 请求参数

- `dateFrom/dateTo` `string|null`
- `hourFrom/hourTo` `int|null`，范围 `0-23`
- `groupBy` `string[]|null`，可选：
  - `date` `hour` `node_id` `node_type` `node_host` `node_public_ip`
- `filters` `object|null`
  - `filters.nodeIds` `int[]|null`
  - `filters.nodeTypes` `string[]|null`
  - `filters.nodeHosts` `string[]|null`
  - `filters.nodePublicIps` `string[]|null`
- `page/pageSize` `int|null`（`pageSize` 最大 `200`）
- `orderBy` `string|null`
- `orderDirection` `asc|desc|null`

### 2.2 返回字段

顶层：

- `data` `array<object>`（已 camelCase）
- `total` `int`
- `page` `int`
- `pageSize` `int`
- `dateFrom/dateTo` `string`
- `hourFrom/hourTo` `int|null`
- `groupBy` `string[]`

`data[]`：

- 维度字段：`date/hour/nodeId/nodeType/nodeHost/nodePublicIp`
- 指标字段：
  - `trafficUpload` `int`
  - `trafficDownload` `int`
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
  - `computeCount` `int`

---

## 3. 节点报表查询（user 维度）

- 方法/路径：`POST /api/v3/admin/report/nodeServerReport/user/query`
- 控制器：`App\Http\Controllers\V3\Admin\ReportController::queryNodeServerReportUser`
- Request：`App\Http\Requests\Admin\NodeServerReportUserQueryRequest`

### 3.1 请求参数

- `dateFrom/dateTo` `string|null`
- `hourFrom/hourTo` `int|null`，范围 `0-23`
- `groupBy` `string[]|null`，可选：
  - `date` `hour` `node_id` `user_id` `app_id` `app_version` `country`
- `filters` `object|null`
  - `filters.nodeIds` `int[]|null`
  - `filters.userIds` `int[]|null`
  - `filters.appIds` `string[]|null`
  - `filters.appVersions` `string[]|null`
  - `filters.countries` `string[]|null`
- `page/pageSize` `int|null`（`pageSize` 最大 `200`）
- `orderBy` `string|null`
- `orderDirection` `asc|desc|null`

### 3.2 返回字段

顶层：

- `data` `array<object>`（已 camelCase）
- `total` `int`
- `page` `int`
- `pageSize` `int`
- `dateFrom/dateTo` `string`
- `hourFrom/hourTo` `int|null`
- `groupBy` `string[]`

`data[]`：

- 维度字段：`date/hour/nodeId/userId/appId/appVersion/country`
- 指标字段：
  - `trafficUpload` `int`
  - `trafficDownload` `int`
  - `computeCount` `int`

---

## 4. 前端字段映射表

### 4.1 node 维度查询映射

| 前端字段 | 后端字段(DB) | 说明 |
|---|---|---|
| date | date | 日期（UTC+8） |
| hour | hour | 小时（0-23） |
| nodeId | node_id | 节点 ID |
| nodeType | node_type | 节点类型 |
| nodeHost | node_host | 节点 host |
| nodePublicIp | node_public_ip | 节点公网 IP |
| trafficUpload | traffic_upload | 上传流量（bytes） |
| trafficDownload | traffic_download | 下载流量（bytes） |
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
| computeCount | compute_count | 样本数 |

### 4.2 user 维度查询映射

| 前端字段 | 后端字段(DB) | 说明 |
|---|---|---|
| date | date | 日期（UTC+8） |
| hour | hour | 小时（0-23） |
| nodeId | node_id | 节点 ID |
| userId | user_id | 用户 ID |
| appId | app_id | 应用 ID |
| appVersion | app_version | 应用版本 |
| country | country | 国家 |
| trafficUpload | traffic_upload | 上传流量（bytes） |
| trafficDownload | traffic_download | 下载流量（bytes） |
| computeCount | compute_count | 样本数 |

### 4.3 realtime 查询映射

说明：实时接口当前返回原始快照字段，未做 camelCase 转换。

| 前端字段(建议) | 后端字段(响应) | 说明 |
|---|---|---|
| nodeId | node_id | 节点 ID |
| nodeType | node_type | 节点类型 |
| ip | ip | 上报来源 IP |
| traffic | traffic | 原始流量字段 |
| alive | alive | 原始 alive 字段 |
| online | online | 原始 online 字段 |
| status | status | 原始 status 字段 |
| metrics | metrics | 原始 metrics 字段 |
| createdAt | created_at | 快照时间 |

---

## 5. 前端对接建议

- node/user 报表接口已返回 camelCase，前端可直接绑定表格字段。
- realtime 接口是原始快照，建议前端做一次字段转换（`snake_case -> camelCase`）。
- 流量单位均为 `bytes`，展示 GB 时建议前端统一按 `bytes / 1024 / 1024 / 1024` 转换。

---

## 6. 默认值与回放

### 6.1 维度默认值

当上报中维度缺失/为空时，落库采用以下默认值：

- `nodeId` -> `0`
- `nodeType` -> `unknown`
- `nodeHost` -> `n.n.n.n`
- `nodePublicIp` -> `0.0.0.0`

### 6.2 OSS 回放

- 命令：`php artisan node_server_report:replay-oss {date}`
- 示例：

```bash
php artisan node_server_report:replay-oss 2026-05-08 --hour=16 --minute=20 --clear-day
```

- 常用参数：
  - `--bucket=yyyymmddHHmm`
  - `--batch=10000`
  - `--chunk=1000`
  - `--dry-run`
  - `--clear-day`
