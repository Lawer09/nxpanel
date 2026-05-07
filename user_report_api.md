# user_report API 文档

本文档基于当前代码实现，给出 `user_report` 相关接口的详细参数与返回字段。

## 2. 管理端查询接口

统一前缀：`POST /api/v3/admin/report/userReport/*/query`

通用返回字段：

- `data` `array<object>`
- `total` `int`
- `page` `int`
- `pageSize` `int`
- `dateFrom` `string` (`YYYY-MM-DD`)
- `dateTo` `string` (`YYYY-MM-DD`)
- `hourFrom` `int|null`
- `hourTo` `int|null`
- `groupBy` `array<string>`

### 2.1 汇总查询

- 路径：`POST /api/v3/admin/report/userReport/summary/query`
- 控制器：`ReportController::queryUserReportSummary`
- Request：`UserReportSummaryQueryRequest`

请求参数：

- `dateFrom` `string|null`
- `dateTo` `string|null`
- `hourFrom` `int|null`，0-23
- `hourTo` `int|null`，0-23
- `groupBy` `array<string>|null`，可选：
  - `date` `hour` `user_id` `app_id` `app_version` `country`
- `filters` `object|null`
  - `filters.userIds` `int[]|null`
  - `filters.appIds` `string[]|null`
  - `filters.appVersions` `string[]|null`
  - `filters.countries` `string[]|null`
- `page` `int|null`
- `pageSize` `int|null`，1-200

`data[]` 字段：

- 不传 `groupBy`：
  - `id` `int`
  - `user_id` `int`
  - `app_id` `string`
  - `app_version` `string`
  - `country` `string`
  - `date` `string`
  - `hour` `int`
  - `report_count` `int`
  - `created_at` `string`
  - `updated_at` `string`
- 传 `groupBy`：
  - 返回所选维度字段
  - `report_count` `int`（`SUM(report_count)`）

### 2.2 节点汇总查询

- 路径：`POST /api/v3/admin/report/userReport/nodeSummary/query`
- 控制器：`ReportController::queryUserReportNodeSummary`
- Request：`UserReportNodeSummaryQueryRequest`

请求参数：

- `dateFrom/dateTo/hourFrom/hourTo`
- `groupBy` 可选：
  - `date` `hour` `node_id` `node_host` `node_type` `probe_stage`
- `filters` 可选：
  - `filters.nodeIds` `int[]|null`
  - `filters.nodeHosts` `string[]|null`
  - `filters.probeStages` `string[]|null`
  - `filters.nodeTypes` `string[]|null`
- `page/pageSize`

`data[]` 字段：

- 不传 `groupBy`：
  - `id` `int`
  - `date` `string`
  - `hour` `int`
  - `node_id` `int`
  - `node_host` `string`
  - `node_type` `string`
  - `probe_stage` `string`
  - `avg_delay` `number`
  - `traffic_usage` `number`
  - `traffic_use_time` `int`
  - `compute_count` `int`
  - `created_at` `string`
  - `updated_at` `string`
- 传 `groupBy`：
  - 返回所选维度字段
  - `avg_delay` `number`（按 `compute_count` 加权）
  - `traffic_usage` `number`（求和）
  - `traffic_use_time` `int`（求和）
  - `compute_count` `int`（求和）

### 2.3 用户流量查询

- 路径：`POST /api/v3/admin/report/userReport/traffic/query`
- 控制器：`ReportController::queryUserReportTraffic`
- Request：`UserReportTrafficQueryRequest`

请求参数：

- `dateFrom/dateTo/hourFrom/hourTo`
- `groupBy` 可选：
  - `date` `hour` `user_id` `app_id` `app_version` `country`
- `filters` 可选：
  - `filters.userIds` `int[]|null`
  - `filters.appIds` `string[]|null`
  - `filters.appVersions` `string[]|null`
  - `filters.countries` `string[]|null`
- `page/pageSize`

`data[]` 字段：

- 不传 `groupBy`：
  - `id` `int`
  - `date` `string`
  - `hour` `int`
  - `user_id` `int`
  - `app_id` `string`
  - `app_version` `string`
  - `country` `string`
  - `traffic_usage` `number`
  - `traffic_use_time` `int`
  - `compute_count` `int`
  - `created_at` `string`
  - `updated_at` `string`
- 传 `groupBy`：
  - 返回所选维度字段
  - `traffic_usage` `number`（求和）
  - `traffic_use_time` `int`（求和）
  - `compute_count` `int`（求和）

### 2.4 节点失败查询

- 路径：`POST /api/v3/admin/report/userReport/nodeFail/query`
- 控制器：`ReportController::queryUserReportNodeFail`
- Request：`UserReportNodeFailQueryRequest`

请求参数：

- `dateFrom/dateTo/hourFrom/hourTo`
- `groupBy` 可选：
  - `date` `hour` `node_id` `node_host` `node_type` `probe_stage` `error_code`
- `filters` 可选：
  - `filters.nodeIds` `int[]|null`
  - `filters.nodeHosts` `string[]|null`
  - `filters.probeStages` `string[]|null`
  - `filters.errorCodes` `string[]|null`
- `page/pageSize`

`data[]` 字段：

- 不传 `groupBy`：
  - `id` `int`
  - `date` `string`
  - `hour` `int`
  - `report_at_ms` `int`
  - `user_id` `int`
  - `app_id` `string`
  - `country` `string`
  - `node_id` `int`
  - `node_host` `string`
  - `node_type` `string`
  - `probe_stage` `string`
  - `error_code` `string`
  - `created_at` `string`
- 传 `groupBy`：
  - 返回所选维度字段
  - `fail_count` `int`（`COUNT(*)`）
  - `last_report_at_ms` `int`（`MAX(report_at_ms)`）

---

## 3. 实时查看接口

- 方法/路径：`GET /api/v3/admin/userReport/realtime`
- 说明：查看最近用户上报缓存列表（用于排查上报实时数据）。

---

## 4. 任务命令

- 聚合：`php artisan user_report:aggregate`
  - `--batch=10000`
  - `--bucket=yyyymmddHHmm`
- OSS 回放：`php artisan user_report:replay-oss {date}`
  - `--hour=HH`
  - `--minute=MM`
  - `--bucket=yyyymmddHHmm`
  - `--batch=10000`
  - `--dry-run`
  - `--clear-day`

---

## 5. 示例请求

### 5.1 汇总查询（按 app + country）

```json
{
  "dateFrom": "2026-05-07",
  "dateTo": "2026-05-07",
  "groupBy": ["app_id", "country"],
  "filters": {
    "appIds": ["com.demo.app"],
    "countries": ["US", "JP"]
  },
  "page": 1,
  "pageSize": 50
}
```

### 5.2 节点失败查询（按节点+错误码聚合）

```json
{
  "dateFrom": "2026-05-07",
  "dateTo": "2026-05-07",
  "hourFrom": 12,
  "hourTo": 14,
  "groupBy": ["node_id", "error_code"],
  "filters": {
    "probeStages": ["node_connect", "post_connect_probe"]
  },
  "page": 1,
  "pageSize": 100
}
```
