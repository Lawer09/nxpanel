# Performance API

管理端 Performance 接口挂载在 `GET /api/v3/admin/{securePath}/performance/*`，本次优化不改变请求参数、返回字段和统计口径，仅优化 `v3_user_report_count` 查询方式和索引。

## Active Users

`GET /performance/activeUsers`

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | date | 否 | 开始日期，默认最近 30 天 |
| dateTo | date | 否 | 结束日期，默认今天 |
| appId | string | 否 | 按 `app_id` 筛选 |
| platform | string | 否 | 按平台筛选 |
| granularity | string | 否 | `day`/`week`/`month`，默认 `day` |

返回 `data/dateFrom/dateTo/granularity`。`data` 中保留 `period`、`periodStart`、`periodEnd`、`activeUsers`、`totalReports`、`newUsers`、`regUsers`。

- `activeUsers`：当前周期内 `v3_user_report_count.user_id` 去重数。
- `newUsers`：用户在相同 `appId/platform` 筛选口径下生命周期首次上报日期落入当前周期的去重用户数。
- `regUsers`：来自 `v2_user.created_at` 的注册用户数。

## Retention

`GET /performance/retention`

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | date | 否 | cohort 开始日期，默认最近 30 天 |
| dateTo | date | 否 | cohort 结束日期，默认昨天 |
| appId | string | 否 | cohort 按 `app_id` 筛选 |
| platform | string | 否 | cohort 按平台筛选 |

返回 `data/dateFrom/dateTo/retentionDays`。`retentionDays` 固定为 `[1,3,7,14,30]`。

- cohort 活跃用户按 `date + appId/platform` 统计。
- 留存用户表示 cohort 用户在目标日期也有上报记录；目标日期活跃判断保持原有口径，不额外按 `appId/platform` 过滤。
- 目标日期超过当前日期时，对应 `day_N` 返回 `null`。

## User Hourly Stats

`GET /performance/userHourlyStats`

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| appId | string | 否 | 按 `app_id` 筛选 |
| platform | string | 否 | 按平台筛选 |
| appVersion | string | 否 | 按 App 版本筛选 |
| clientCountry | string | 否 | 按客户端国家筛选 |

返回最近 24 个整点小时桶，包含 `data/start/end`。`data` 中保留 `time`、`newUsers`、`activeUsers`。

- `activeUsers`：该小时内去重上报用户数。
- `newUsers`：用户在相同筛选口径下生命周期首次上报小时落入该小时桶的去重用户数。

## Performance Notes

- `retention` 已从循环多次 join 查询改为批量聚合。
- `activeUsers` 和 `userHourlyStats` 的新增用户统计使用候选窗口加 `NOT EXISTS` 判断更早上报，避免全表 `MIN()` 聚合。
- `userHourlyStats` 使用 `date/hour` 范围条件，不再在 `WHERE` 中对列执行 `STR_TO_DATE(CONCAT(...))`。
- 需要执行 `2026_07_07_120000_add_user_report_count_performance_indexes.php` migration，为 `v3_user_report_count` 添加复合索引。
