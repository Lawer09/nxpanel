# Firebase Report API（V3）

本文档描述 Firebase 聚合报表相关的管理端接口。

## 基本信息

- 路由前缀：`/api/v3/admin/{securePath}/firebase-analytics`
- 鉴权：`admin` + `log`
- 返回：统一 `code/msg/data`

---

## 1) 日期范围同步

- 方法/路径：`POST /report/sync`
- 说明：触发 Firebase 聚合命令按日期范围重算

请求参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| dateFrom | string | 是 | 开始日期，`YYYY-MM-DD` |
| dateTo | string | 是 | 结束日期，`YYYY-MM-DD` |

返回字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| success | bool | 是否触发成功（exitCode=0） |
| exitCode | int | Artisan 命令退出码 |
| dateFrom | string | 开始日期 |
| dateTo | string | 结束日期 |
| message | string | 命令输出内容 |

请求示例：

```json
{
  "dateFrom": "2026-05-26",
  "dateTo": "2026-05-29"
}
```

---

## 2) 用户汇总查询

- 方法/路径：`POST /report/user-summary/query`
- 数据表：`firebase_report_user_summary`

支持参数（与 `queryUserReportSummary` 对齐）：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| dateFrom | string | 否 | 开始日期，默认今天 |
| dateTo | string | 否 | 结束日期，默认今天 |
| hourFrom | int | 否 | 起始小时，0-23 |
| hourTo | int | 否 | 结束小时，0-23 |
| groupBy | string[] | 否 | 分组字段 |
| filters | object | 否 | 过滤条件 |
| page | int | 否 | 页码，默认 1 |
| pageSize | int | 否 | 每页数量，默认 50，最大 200 |
| orderBy | string | 否 | 排序字段 |
| orderDirection | string | 否 | `asc`/`desc`，默认 `desc` |

`groupBy` 可选值：

- `date`
- `hour`
- `app_id`
- `app_version`
- `platform`
- `country`
- `network_type`

`filters` 可选字段：

- `appIds`
- `appVersions`
- `platforms`
- `countries`
- `networkTypes`

主要返回指标：

- `newUserCount`
- `activeDeviceCount`
- `dauDeviceCount`
- `eventCount`

---

## 3) 节点汇总查询

- 方法/路径：`POST /report/node/query`
- 数据表：`firebase_report_node`

支持参数（与 `queryUserReportSummary` 对齐）：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| dateFrom | string | 否 | 开始日期，默认今天 |
| dateTo | string | 否 | 结束日期，默认今天 |
| hourFrom | int | 否 | 起始小时，0-23 |
| hourTo | int | 否 | 结束小时，0-23 |
| groupBy | string[] | 否 | 分组字段 |
| filters | object | 否 | 过滤条件 |
| page | int | 否 | 页码，默认 1 |
| pageSize | int | 否 | 每页数量，默认 50，最大 200 |
| orderBy | string | 否 | 排序字段 |
| orderDirection | string | 否 | `asc`/`desc`，默认 `desc` |

`groupBy` 可选值：

- `date`
- `hour`
- `app_id`
- `app_version`
- `country`
- `node_id`
- `node_host`
- `node_name`
- `node_country`
- `node_region`
- `protocol`

`filters` 可选字段：

- `appIds`
- `appVersions`
- `countries`
- `nodeIds`
- `nodeHosts`
- `nodeCountries`
- `protocols`

主要返回指标：

- `totalCount`
- `successCount`
- `failCount`
- `successRate`
- `avgConnectMs`
- `maxConnectMs`

---

## 相关文件

- `app/Http/Controllers/V3/Admin/Firebase/FirebaseReportController.php`
- `app/Http/Requests/Admin/FirebaseReportSyncRequest.php`
- `app/Http/Requests/Admin/FirebaseReportUserSummaryQueryRequest.php`
- `app/Http/Requests/Admin/FirebaseReportNodeQueryRequest.php`
- `app/Console/Commands/AggregateFirebaseReports.php`
- `app/Http/Routes/V3/AdminRoute.php`
