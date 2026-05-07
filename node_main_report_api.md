# node_main_report API 文档

本文档基于当前代码实现，整理节点主报表与子表校对接口的详细参数和返回字段。

## 1. 节点主报表查询

- 方法/路径：`POST /api/v3/admin/report/node/query`
- 控制器：`App\Http\Controllers\V3\Admin\ReportController::queryNode`
- 服务：`App\Services\NodeMainReportService::query`
- Request：`App\Http\Requests\Admin\NodeMainReportQueryRequest`

## 1.1 请求参数

- `dateFrom` `string|null`，日期
- `dateTo` `string|null`，日期
- `groupBy` `string[]`，必填，允许值：
  - `date`
  - `hour`
  - `node_id`
  - `node_name`
  - `app_id`
  - `app_version`
  - `platform`
  - `client_country`
  - `client_isp`
  - `node_host`
  - `machine_ip`
  - `machine_ip_isp`
  - `node_protocol`
- `filters` `object|null`
  - `filters.nodeIds` `int[]|null`
  - `filters.appIds` `string[]|null`
  - `filters.appVersions` `string[]|null`
  - `filters.platforms` `string[]|null`
  - `filters.clientCountries` `string[]|null`
  - `filters.clientIsps` `string[]|null`
  - `filters.nodeProtocols` `string[]|null`
  - `filters.includeExternal` `bool|null`（默认 false）
- `fillUnknown` `bool|null`（默认 true，空维度填充为“未知”）
- `page` `int|null`（默认 1）
- `pageSize` `int|null`（默认 50，最大 200）

## 1.2 返回字段

顶层：

- `data` `array<object>`
- `total` `int`
- `page` `int`
- `pageSize` `int`
- `groupBy` `array<string>`
- `metric_availability` `object`
  - `avg_delay`
  - `success_count`
  - `failed_count`
  - `node_connect_error_count`
  - `post_connect_probe_error_count`
  - `client_report_traffic`
  - `node_push_traffic`（`full|unavailable_by_group`）
  - `bandwidth`（`partial`）
- `bandwidth_source` `string`（当前为 `machine_config|ip_pool_config`）
- `dateFrom` `string`
- `dateTo` `string`

`data[]` 常见字段（按 `groupBy` 动态出现维度字段）：

- 维度字段：`date/hour/node_id/node_name/app_id/app_version/platform/client_country/client_isp/node_host/machine_ip/machine_ip_isp/node_protocol`
- 指标字段：
  - `avg_delay` `number`
  - `success_count` `int`
  - `failed_count` `int`
  - `node_connect_error_count` `int`
  - `post_connect_probe_error_count` `int`
  - `client_report_traffic_usage_mb` `number`
  - `client_report_usage_seconds` `int`
  - `client_report_count` `int`
  - `node_push_traffic_u_bytes` `int|null`
  - `node_push_traffic_d_bytes` `int|null`
  - `node_push_traffic_total_bytes` `int|null`
  - `bandwidth` `number|null`
  - `up_bandwidth` `number|null`
  - `down_bandwidth` `number|null`

注：当 `groupBy` 包含客户端维度（如 `app_id/platform/client_isp`）时，`node_push_traffic_*` 会返回 `null`。

---

## 2. 子表校对查询

- 方法/路径：`POST /api/v3/admin/report/node/subtable/query`
- 控制器：`App\Http\Controllers\V3\Admin\ReportController::queryNodeSubTable`
- 服务：`App\Services\NodeSubReportService::query`
- Request：`App\Http\Requests\Admin\NodeSubReportQueryRequest`

## 2.1 请求参数

- `subTable` `string`，必填，可选：
  - `performance` -> `v2_node_performance_aggregated`
  - `probe` -> `v2_node_probe_aggregated`
  - `traffic` -> `v2_node_traffic_aggregated`
  - `server_detail` -> `v2_stat_server_detail`
  - `main_aggregated` -> `v2_node_main_report_aggregated`
- `date` `string`，必填
- `hour` `int|null`
- `minute` `int|null`（会自动对齐 5 分钟）
- `groupBy` `string[]|null`，可选维度全集：
  - `date/hour/minute/scope/node_id/server_id/node_ip/client_country/platform/client_isp/app_id/app_version/status/probe_stage/error_code/server_type/year/month/day`
- `filters` `object|null`
  - `filters.nodeIds` `int[]|null`
  - `filters.appIds` `string[]|null`
  - `filters.appVersions` `string[]|null`
  - `filters.platforms` `string[]|null`
  - `filters.clientCountries` `string[]|null`
  - `filters.clientIsps` `string[]|null`
  - `filters.statuses` `string[]|null`（仅 `probe`）
  - `filters.probeStages` `string[]|null`（仅 `probe`）
  - `filters.errorCodes` `string[]|null`（仅 `probe`）
  - `filters.includeExternal` `bool|null`（默认 false）
- `page` `int|null`
- `pageSize` `int|null`（最大 200）

## 2.2 返回字段

顶层：

- `data` `array<object>`
- `total` `int`
- `page` `int`
- `pageSize` `int`
- `subTable` `string`
- `groupBy` `array<string>`（实际生效维度）
- `metricMap` `array<string>`（该子表输出指标）
- `date` `string`
- `hour` `int|null`
- `minute` `int|null`

`data[]`：

- 包含 `groupBy` 里声明的维度字段
- 包含 `metricMap` 列出的指标字段

各 `subTable` 的 `metricMap`：

- `performance`
  - `row_count`
  - `total_count`
  - `avg_delay_weighted`
- `probe`
  - `row_count`
  - `total_count`
  - `success_count`
  - `failed_like_count`
- `traffic`
  - `row_count`
  - `report_count`
  - `total_usage_mb`
  - `total_usage_seconds`
- `server_detail`
  - `row_count`
  - `u_bytes`
  - `d_bytes`
  - `total_bytes`
- `main_aggregated`
  - `row_count`
  - `delay_weight`
  - `success_count`
  - `failed_count`
  - `client_report_traffic_usage_mb`
  - `node_push_traffic_total_bytes`

---

## 3. 相关命令

- 主表聚合：`php artisan perf:aggregate-main-table`
- 按天重建：`php artisan perf:rebuild-main-table-day YYYY-MM-DD`
- 按天补算：`php artisan perf:rebuild-main-table-day YYYY-MM-DD --keep-existing`

---

## 4. 示例请求

### 4.1 主表查询（多维）

```json
{
  "dateFrom": "2026-05-01",
  "dateTo": "2026-05-07",
  "groupBy": ["date", "hour", "node_id", "platform", "app_id"],
  "filters": {
    "nodeIds": [12, 13],
    "appIds": ["com.demo.app"],
    "includeExternal": false
  },
  "fillUnknown": true,
  "page": 1,
  "pageSize": 50
}
```

### 4.2 子表校对（probe）

```json
{
  "subTable": "probe",
  "date": "2026-05-07",
  "hour": 13,
  "minute": 25,
  "groupBy": ["date", "hour", "minute", "node_id", "status", "probe_stage"],
  "filters": {
    "nodeIds": [12],
    "appIds": ["com.demo.app"],
    "statuses": ["success", "failed", "timeout", "cancelled"],
    "probeStages": ["node_connect", "post_connect_probe"]
  },
  "page": 1,
  "pageSize": 50
}
```
