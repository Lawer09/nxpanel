# user_report API 文档

本文档基于当前代码实现，给出 `user_report` 相关接口的详细参数与返回字段。

## 1. 用户侧批量上报接口

- 方法/路径：`POST /api/v3/user/performance/batchReport`
- 说明：用户侧批量上报节点性能、用户默认数据和广告价值数据。请求线程只写 Redis raw payload 与实时查看缓存，不同步写入广告价值统计表。

新增请求字段：

- `ads_value_reports` `array<object>|null` 可选，最多 100 条。
  - `value_micros` `int` 必填，最小值 0，客户端上报的广告价值 micros。
  - `currency` `string` 必填，最大 8 位，聚合时会 `trim + strtoupper`。

广告价值处理口径：

- `ads_value_reports` 会随原始 payload 写入 `perf:raw:*` 和 `user_report:raw:*`，并在管理端实时查看缓存中透传。
- `user_report:aggregate` 从 `user_report:raw:*` 聚合广告价值，按 `metadata.timestamp` 归入 UTC+8 日期和小时。
- 聚合前通过 `CurrencyRateService` 按上报日期读取币种到 USD 的汇率：优先进程内缓存，其次 Redis hash `currency_rates:to_usd:{YYYY-MM-DD}`，最后读取 `currency_rates_daily` 日快照；`USD` 固定为 `1`。
- `currency-rates:sync` 每日同步常见 21 个币种到 USD，默认包含 `HKD`；`CURRENCY_RATE_OVERRIDE_TO_USD` 仅作为紧急覆盖或兜底，不再作为长期人工维护的主汇率表。
- 聚合路径不调用外部汇率接口，也不调用 `Helper::exchange()`；找不到汇率的币种会跳过并记录 warning，不影响整桶聚合成功。
- 留存价值来源按用户在同一 `app_id` 下的首次上报日计算：`day0` 为首次上报当日，`day1` 为次日，依次类推。

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
- `orderBy` `string|null`，可选：`date/hour/user_id/app_id/app_version/country/report_count/id/created_at/updated_at`
- `orderDirection` `string|null`，`asc|desc`，默认 `desc`

`data[]` 字段：

- 不传 `groupBy`：
  - `id` `int`
  - `userId` `int`
  - `appId` `string`
  - `appVersion` `string`
  - `country` `string`
  - `date` `string`
  - `hour` `int`
  - `reportCount` `int`
  - `createdAt` `string`
  - `updatedAt` `string`
- 传 `groupBy`：
  - 返回所选维度字段
  - `reportCount` `int`（`SUM(report_count)`）

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
- `orderBy` `string|null`，可选：`date/hour/node_id/node_host/node_type/probe_stage/avg_delay/traffic_usage/traffic_use_time/compute_count/success_count/fail_count/success_rate/id/created_at/updated_at`
- `orderDirection` `string|null`，`asc|desc`，默认 `desc`

`data[]` 字段：

- 不传 `groupBy`：
  - `id` `int`
  - `date` `string`
  - `hour` `int`
  - `nodeId` `int`
  - `nodeHost` `string`
  - `nodeType` `string`
  - `probeStage` `string`
  - `avgDelay` `number`
  - `trafficUsage` `number`
  - `trafficUseTime` `int`
  - `computeCount` `int`
  - `successCount` `int`
  - `failCount` `int`
  - `successRate` `number`（`ROUND(100 * successCount / (successCount + failCount), 2)`）
  - `createdAt` `string`
  - `updatedAt` `string`
- 传 `groupBy`：
  - 返回所选维度字段
  - `avgDelay` `number`（按 `compute_count` 加权）
  - `trafficUsage` `number`（求和）
  - `trafficUseTime` `int`（求和）
  - `computeCount` `int`（求和）
  - `successCount` `int`（`SUM(success_count)`）
  - `failCount` `int`（`SUM(fail_count)`）
  - `successRate` `number`（`ROUND(100 * SUM(success_count) / (SUM(success_count)+SUM(fail_count)), 2)`）

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
- `orderBy` `string|null`，可选：`date/hour/user_id/app_id/app_version/country/traffic_usage/traffic_use_time/compute_count/id/created_at/updated_at`
- `orderDirection` `string|null`，`asc|desc`，默认 `desc`

`data[]` 字段：

- 不传 `groupBy`：
  - `id` `int`
  - `date` `string`
  - `hour` `int`
  - `userId` `int`
  - `appId` `string`
  - `appVersion` `string`
  - `country` `string`
  - `trafficUsage` `number`
  - `trafficUseTime` `int`
  - `computeCount` `int`
  - `createdAt` `string`
  - `updatedAt` `string`
- 传 `groupBy`：
  - 返回所选维度字段
  - `trafficUsage` `number`（求和）
  - `trafficUseTime` `int`（求和）
  - `computeCount` `int`（求和）

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
- `orderBy` `string|null`，可选：`date/hour/node_id/node_host/node_type/probe_stage/error_code/report_at_ms/fail_count/last_report_at_ms/id/created_at`
- `orderDirection` `string|null`，`asc|desc`，默认 `desc`

`data[]` 字段：

- 不传 `groupBy`：
  - `id` `int`
  - `date` `string`
  - `hour` `int`
  - `reportAtMs` `int`
  - `userId` `int`
  - `appId` `string`
  - `country` `string`
  - `nodeId` `int`
  - `nodeHost` `string`
  - `nodeType` `string`
  - `probeStage` `string`
  - `errorCode` `string`
  - `createdAt` `string`
- 传 `groupBy`：
  - 返回所选维度字段
  - `failCount` `int`（`COUNT(*)`）
  - `lastReportAtMs` `int`（`MAX(report_at_ms)`）

---

## 3. 实时查看接口

- 方法/路径：`GET /api/v3/admin/userReport/realtime`
- 说明：查看最近用户上报缓存列表（用于排查上报实时数据）。

---

## 4. 任务命令

- 聚合：`php artisan user_report:aggregate`
  - `--batch=10000`
  - `--bucket=yyyymmddHHmm`
  - 写入 `v3_user_report_summary`、`v3_user_report_node`、`v3_user_report_user`、`v3_user_report_node_fail`、`v3_user_ad_value_hourly`、`v3_user_app_first_report`
- 汇率同步：`php artisan currency-rates:sync`
  - `--date=YYYY-MM-DD`
  - `--currencies=USD,CNY,HKD`
  - `--force`
  - 写入 `currency_rates_daily`，并刷新 Redis hash `currency_rates:to_usd:{YYYY-MM-DD}`
- OSS 回放：`php artisan user_report:replay-oss {date}`
  - `--hour=HH`
  - `--minute=MM`
  - `--bucket=yyyymmddHHmm`
  - `--batch=10000`
  - `--dry-run`
  - `--clear-day` 会同时清理 `v3_user_ad_value_hourly` 当日数据，避免广告价值 replay 后重复累加

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
