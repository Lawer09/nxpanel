# user_report_hourly API 文档

本文档参照 `node_server_report_api.md` 的结构，定义用户小时报表（`user_report_hourly`）统一查询接口与字段映射。

数据来源（按小时聚合后写入 `v3_report_user_hourly`）：

- `v3_user_report_user`（由 `v3_user_report_node_traffic` 改名）
- `v3_node_server_report_user`

---

## 1. 用户报表查询

- 方法/路径：`POST /api/v3/admin/report/userReport/query`
- 说明：查询统一用户报表（用户维度）。

### 1.1 请求参数

- `dateFrom/dateTo` `string|null`
- `hourFrom/hourTo` `int|null`，范围 `0-23`
- `groupBy` `string[]|null`，可选：
  - `date` `hour` `user_id` `app_id` `app_version` `country`
- `filters` `object|null`
  - `filters.userIds` `int[]|null`
  - `filters.appIds` `string[]|null`
  - `filters.appVersions` `string[]|null`
  - `filters.countries` `string[]|null`
- `page/pageSize` `int|null`（`pageSize` 最大 `200`）
- `orderBy` `string|null`
- `orderDirection` `asc|desc|null`

说明：

- 用户报表不包含 `node_id` 维度。

请求示例：

```json
{
  "dateFrom": "2026-05-08",
  "dateTo": "2026-05-08",
  "groupBy": ["date", "hour", "app_id", "country"],
  "filters": {
    "appIds": ["com.demo.app"],
    "countries": ["US", "JP"]
  },
  "page": 1,
  "pageSize": 50,
  "orderBy": "traffic_download",
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

- 维度字段：`date/hour/userId/appId/appVersion/country`
- 指标字段：
  - `trafficUsage` `number`（KB，用户上报原始单位 MB，按 `*1024` 转换）
  - `trafficUseTime` `int`
  - `trafficUpload` `number`（KB，节点上报原始单位 B，按 `/1024` 转换）
  - `trafficDownload` `number`（KB，节点上报原始单位 B，按 `/1024` 转换）
  - `reportCountUser` `int`
  - `reportCountNode` `int`

---

## 2. 前端字段映射表

| 前端字段 | 后端字段(DB) | 说明 |
|---|---|---|
| date | date | 日期（UTC+8） |
| hour | hour | 小时（0-23） |
| userId | user_id | 用户 ID |
| appId | app_id | 应用 ID |
| appVersion | app_version | 应用版本 |
| country | country | 国家 |
| trafficUsage | traffic_usage | 用户上报流量（KB，用户上报原始单位 MB，按 `*1024` 转换） |
| trafficUseTime | traffic_use_time | 用户上报使用时长（秒） |
| trafficUpload | traffic_upload | 节点上报上传流量（KB，节点上报原始单位 B，按 `/1024` 转换） |
| trafficDownload | traffic_download | 节点上报下载流量（KB，节点上报原始单位 B，按 `/1024` 转换） |
| reportCountUser | report_count_user | 用户侧样本数 |
| reportCountNode | report_count_node | 节点侧样本数 |

---

## 3. 数据口径说明

- `report_count_user` 来自 `v3_user_report_user`，`report_count_node` 来自 `v3_node_server_report_user`。
- 两类样本计数字段不合并，前端按场景选择展示。
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
