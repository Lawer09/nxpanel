# 项目小时报表 API

本文档说明 `project_report_hourly` 表的新字段、聚合来源、查询接口和手动同步接口。

## 1. 查询接口

- 方法/路径：`POST /api/v3/admin/{securePath}/report/project/hourly/query`
- 控制器：`ReportController::queryProjectReportHourly`
- Request：`ProjectReportHourlyQueryRequest`
- 返回格式：统一 JSON 响应，`data` 内包含列表、分页和筛选回显。
- 查询缓存：JSON 查询结果缓存 60 秒；缓存 key 包含日期、小时、分组、筛选、分页和排序参数。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 开始日期，默认昨天 |
| dateTo | string | 否 | 结束日期，默认今天 |
| hourFrom | int | 否 | 开始小时，0-23 |
| hourTo | int | 否 | 结束小时，0-23 |
| groupBy | string[] | 否 | 分组维度：`reportDate`、`hour`、`projectCode`、`country` |
| filters.projectCodes | string[] | 否 | 项目代号筛选 |
| filters.countries | string[] | 否 | 国家筛选，服务端统一转大写 |
| filters.exclude.projectCodes | string[] | 否 | 排除项目代号筛选；与 `filters.projectCodes` 同时存在时先包含再排除 |
| filters.exclude.countries | string[] | 否 | 排除国家筛选，服务端统一转大写；列表、summary 和 Top3 收益国家使用同一口径 |
| filters.adStatuses | string[] | 否 | 投放状态筛选，匹配 `project_projects.ad_status`，仅过滤不返回 |
| filters.appPlatforms | string[] | 否 | 应用平台筛选，匹配 `project_projects.app_platform`，仅过滤不返回 |
| filters.departments | string[] | 否 | 部门筛选，匹配 `project_projects.department`，仅过滤不返回 |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 50，最大 400 |
| orderBy | string | 否 | 支持维度字段和日报同款指标字段，例如 `adRevenue`、`adRequests`、`trafficCost`、`profit`、`roi` |
| orderDirection | string | 否 | `asc` 或 `desc`，默认 `desc` |

### 返回字段

小时报表返回字段与项目日报保持一致，并额外返回 `hour`：

```json
{
  "id": 1,
  "reportDate": "2026-06-29",
  "hour": 10,
  "projectCode": "A001",
  "country": "US",
  "newUsers": 12,
  "reportNewUsers": 12,
  "fbNewUsers": 0,
  "dauUsers": 85,
  "fbDauUsers": 0,
  "adRevenue": "15.230000",
  "adRequests": 1200,
  "adMatchedRequests": 1000,
  "adImpressions": 900,
  "adClicks": 30,
  "adEcpm": "16.922222",
  "adCtr": "3.333333",
  "adMatchRate": "83.333333",
  "isLimited": false,
  "adShowRate": "90.000000",
  "impressionsPerUser": "10.588235",
  "arpu": "0.179176",
  "adSpendCost": "0.000000",
  "adSpendCpi": null,
  "adSpendCpc": null,
  "adSpendCpm": null,
  "trafficUsageMb": "1024.000000",
  "trafficCost": "0.160000",
  "totalCost": "0.160000",
  "trafficCostRatio": "1.000000",
  "profit": "15.070000",
  "roi": "95.187500",
  "updatedAt": "2026-06-29 10:05:00"
}
```

说明：

- 当返回行包含唯一 `projectCode` 时，会附带项目元数据字段，例如 `adStatus`、`appPlatform`、`appName`、`packageName` 等现有允许返回的项目字段。
- 当 `groupBy` 不包含某个维度时，该维度字段返回 `null`。
- `isLimited` 根据当前返回行自身的 `adMatchRate` 判断：`adMatchRate < 70` 返回 `true`，大于等于 `70` 返回 `false`；`adMatchRate` 为 `null` 时返回 `null`。
- `adSpendCost/adSpendCpi/adSpendCpc/adSpendCpm` 来源于 `ad_spend_report_hourly` 小时投放表，按当前小时维度聚合后实时计算。

## 2. 表结构

- 表名：`project_report_hourly`
- 唯一维度：`report_date + hour + project_code + country`
- 字段结构：与 `project_daily_aggregates` 保持一致，仅额外增加 `hour` 字段。
- 已移除旧字段：`date`、`install_users`、`ros`。

主要字段：

| 字段 | 说明 |
| --- | --- |
| report_date | 报表日期 |
| hour | 小时，0-23 |
| project_code | 项目代号 |
| country | 国家，空值统一为 `XX` |
| new_users | 小时新增用户，取用户生命周期首次上报所在小时 |
| report_new_users | 小时上报新增用户，当前与 `new_users` 同源 |
| fb_new_users | Firebase 小时新增，当前固定 0 |
| dau_users | 小时活跃用户，来自 `v3_user_report_count` 去重用户数 |
| fb_dau_users | Firebase 小时活跃，当前固定 0 |
| ad_revenue/ad_requests/... | 小时广告收益与请求指标，来自 `ad_revenue_hourly` |
| ad_spend_* | 小时投放字段，来自 `ad_spend_report_hourly` |
| traffic_usage_mb | 小时流量用量，来自 `traffic_platform_usage_hourly` |
| traffic_cost | `traffic_usage_mb * 0.16 / 1024` |
| profit | `ad_revenue - ad_spend_cost - traffic_cost` |
| roi | `ad_revenue / (ad_spend_cost + traffic_cost)`，分母为 0 时返回 null |

## 3. 数据来源与映射

### 用户数据

- 来源表：`v3_user_report_count`
- 项目映射：`project_user_app_map.app_id = v3_user_report_count.app_id` 且 `project_user_app_map.enabled = 1`
- 维度：`date + hour + project_code + client_country`
- `dau_users = COUNT(DISTINCT user_id)`
- `new_users/report_new_users`：用户在 `v3_user_report_count` 的生命周期首次上报小时落入当前小时则计入。

### 广告收益

- 来源表：`ad_revenue_hourly`
- 项目映射：复用项目日报广告收益映射逻辑，通过 `project_ad_platform_accounts` 按账号或应用映射到项目代号。
- 维度：`report_date + HOUR(report_hour) + project_code + country_code`
- 指标：`ad_revenue`、`ad_requests`、`ad_matched_requests`、`ad_impressions`、`ad_clicks`。
- 比率：`ad_ecpm/ad_ctr/ad_match_rate/ad_show_rate` 基于小时聚合后的分子分母实时重算。

### 流量数据

- 来源表：`traffic_platform_usage_hourly`
- 项目映射：`project_traffic_platform_accounts`。
- 支持账号级绑定和子账号绑定：当项目绑定记录中存在空 `external_uid` 时按账号聚合，否则按绑定的 `external_uid` 列表过滤。
- `traffic_cost = traffic_usage_mb * 0.16 / 1024`。

### 投放数据

- 当前暂不接入小时投放表。
- `ad_spend_cost = 0`，`ad_spend_cpi/ad_spend_cpc/ad_spend_cpm = null`。
- 后续新增 `ad_spend_platform_reports_hourly` 后再按小时投放点击/展示/花费计算。

## 4. 手动同步接口

- 方法/路径：`POST /api/v3/admin/{securePath}/projects/aggregate-hourly`
- 控制器：`ProjectController::aggregateHourly`
- Request：`ProjectAggregateHourlyRequest`
- 行为：同步调用 `project:aggregate-hourly` 命令，按条件删除并重建 `project_report_hourly` 对应小时数据。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期，`YYYY-MM-DD` |
| endDate | string | 是 | 结束日期，必须大于等于 `startDate` |
| hourFrom | int | 否 | 开始小时，0-23，默认 0 |
| hourTo | int | 否 | 结束小时，0-23，默认 23 |
| projectId | int | 否 | 项目 ID；传入后只重算该项目 |

### 请求示例

```json
{
  "startDate": "2026-06-29",
  "endDate": "2026-06-29",
  "hourFrom": 9,
  "hourTo": 12,
  "projectId": 12
}
```

### 返回示例

```json
{
  "success": true,
  "startDate": "2026-06-29",
  "endDate": "2026-06-29",
  "hourFrom": 9,
  "hourTo": 12,
  "projectId": 12,
  "exitCode": 0,
  "output": "Start aggregating project hourly data..."
}
```

## 5. 命令与调度

### 命令

```bash
php artisan project:aggregate-hourly
php artisan project:aggregate-hourly --start-date=2026-06-29 --end-date=2026-06-29
php artisan project:aggregate-hourly --start-date=2026-06-29 --end-date=2026-06-29 --hour-from=9 --hour-to=12
php artisan project:aggregate-hourly --start-date=2026-06-29 --end-date=2026-06-29 --project-id=12
```

### 默认行为

- 不传日期和小时参数时，命令刷新当天 `0` 点到当前小时的项目小时聚合数据，适合覆盖投放小时数据后续回刷导致的当日历史小时变化。
- 传入日期但不传小时参数时，刷新该日期范围内 0-23 点全部小时。
- 写入使用 `upsert`，每 500 行分批写入。
- 默认聚合会先汇总各来源数据，确认存在可重建数据后再删除并写入对应小时范围，避免源数据临时为空时清空已有小时聚合。

### 调度

系统调度已增加：

```php
$schedule->command('project:aggregate-hourly')->hourlyAt(5)->onOneServer()->withoutOverlapping(55);
$schedule->command('project:prune-hourly --days=30')->dailyAt('0:30')->onOneServer()->withoutOverlapping(10);
```

该调度不依赖 `project:aggregate-daily`，小时表由独立命令维护。

## 6. 数据保留

- `project_report_hourly` 仅保留最近 30 天数据。
- 清理命令：`php artisan project:prune-hourly --days=30`。
- 调度时间：每天 `00:30` 执行一次。
- 删除条件：`report_date < today - 30 days`。
- 清理按 `id` 分批删除，默认每批 1000 行，可通过 `--chunk` 调整。
- 可使用 `--dry-run` 只统计待删除行数，不执行删除。

## Top3 收益国家说明

- 项目小时报表 JSON 查询返回行新增 `topRevenueCountries` 字段，表示该行对应维度范围内广告收益最高的前 3 个国家。
- 字段结构为数组：`country` 为国家代码，`adRevenue` 为该国家收益，`ratio` 为该国家收益占当前行维度范围总收益的比例，金额和比例均保留 6 位小数。
- 计算来源为 `project_report_hourly.ad_revenue`，按当前返回行可确定的 `reportDate`、`hour`、`projectCode`、`country` 维度以及请求 `dateFrom/dateTo/hourFrom/hourTo`、筛选条件批量聚合；不对每一行单独查询。
- 当返回行包含 `country` 维度时，该字段只返回当前国家及其占比；当不包含 `country` 维度时返回当前小时/日期/项目范围内收益 Top3 国家。
- 无收益或总收益小于等于 0 时返回空数组 `[]`。
- 该字段跟随项目小时 JSON 查询结果缓存 60 秒。
## 小时报表 Summary 说明

- 项目小时报表 JSON 查询返回 `summary` 字段，位于返回数据中与 `data`、`total`、`page`、`pageSize` 同级。
- `summary` 基于当前 `dateFrom/dateTo/hourFrom/hourTo/filters` 的全量筛选结果计算，不受分页和当前页数据影响。
- 汇总字段与日报 summary 口径保持一致，包括 `newUsers`、`adRevenue`、`adRequests`、`adEcpm`、`adCtr`、`adMatchRate`、`adShowRate`、`adSpendCost`、`trafficCost`、`totalCost`、`trafficCostRatio`、`profit`、`roi` 等。
- 由于 `project_report_hourly` 当前未保存投放点击数和投放展示数分母，小时 summary 中 `adSpendCpc`、`adSpendCpm` 暂返回 `null`，与小时分组行保持一致。
- 小时 summary 不包含 `topRevenueCountries`，该字段仅在列表行返回。
- 该字段跟随项目小时 JSON 查询结果缓存 60 秒。

## 排除筛选说明

- `filters.exclude.projectCodes` 和 `filters.exclude.countries` 用于从当前小时报表筛选范围中排除指定项目或国家。
- 正向筛选与排除筛选同时存在时，服务端按“先包含、再排除”的交集口径处理。
- 小时报表列表、summary 和 `topRevenueCountries` 均使用相同排除筛选口径。