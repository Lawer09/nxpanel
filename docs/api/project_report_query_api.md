# 项目报表查询与导出接口

## 基本说明

- 管理端查询路径：`POST /api/v3/{secure_path}/report/project/query`
- 管理端导出路径：`POST /api/v3/{secure_path}/report/project/export`
- 应用端查询路径：`POST /api/v3/application/report/project/query`
- 控制器：`App\Http\Controllers\V3\Admin\ReportController`
- Service：`App\Services\ProjectReportService`

项目日报查询与导出共用同一套筛选、分组、排序逻辑。导出接口仅开放管理端，不开放 application 路由。

## 查询接口

### 请求参数

```json
{
  "dateFrom": "2026-06-01",
  "dateTo": "2026-06-05",
  "groupBy": ["reportDate", "projectCode"],
  "filters": {
    "projectCodes": ["A003"],
    "countries": ["US"],
    "adStatuses": ["running"],
    "appPlatforms": ["android"],
    "exclude": {
      "projectCodes": ["A002"],
      "countries": ["BR", "IN"]
    }
  },
  "page": 1,
  "pageSize": 50,
  "orderBy": "adRevenue",
  "orderDirection": "desc"
}
```

### 字段说明

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 开始日期，格式 `Y-m-d` |
| dateTo | string | 否 | 结束日期，格式 `Y-m-d` |
| groupBy | array | 否 | 聚合维度，支持 `reportDate`、`projectCode`、`country` |
| filters.projectCodes | array | 否 | 项目编码过滤 |
| filters.countries | array | 否 | 国家过滤，内部会转为大写 |
| filters.exclude.projectCodes | array | 否 | 排除项目编码过滤；与 `filters.projectCodes` 同时存在时先包含再排除 |
| filters.exclude.countries | array | 否 | 排除国家过滤，内部会转为大写；summary、Top3 收益国家和 CSV 导出使用同一口径 |
| filters.adStatuses | array | 否 | 项目投放状态过滤，匹配 `project_projects.ad_status`；仅用于筛选，不在报表返回字段中输出 |
| filters.appPlatforms | array | 否 | 项目应用平台过滤，匹配 `project_projects.app_platform` |
| page | integer | 否 | 页码，默认 `1` |
| pageSize | integer | 否 | 每页条数，默认 `50`，最大 `200` |
| orderBy | string | 否 | 排序字段 |
| orderDirection | string | 否 | `asc` 或 `desc` |

### 支持的排序字段

- `reportDate`
- `projectCode`
- `country`
- `newUsers`
- `reportNewUsers`
- `fbNewUsers`
- `dauUsers`
- `fbDauUsers`
- `adRevenue`
- `adRequests`
- `adMatchedRequests`
- `adImpressions`
- `adClicks`
- `adEcpm`
- `adCtr`
- `adMatchRate`
- `adShowRate`
- `adSpendCost`
- `adSpendCpi`
- `adSpendCpc`
- `adSpendCpm`
- `trafficUsageMb`
- `trafficCost`
- `totalCost`
- `trafficCostRatio`
- `profit`
- `roi`
- `id`
- `updatedAt`

### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "data": [
      {
        "reportDate": "2026-06-01",
        "projectCode": "A003",
        "isLimited": false,
        "adStatus": "running",
        "appPlatform": "android",
        "adspowerEnv": "env-placeholder",
        "developerGmail": "developer@example.com",
        "appName": "Example VPN",
        "packageName": "com.example.vpn",
        "domainInfoStatus": "completed",
        "domainUrl": "https://example.com",
        "country": "US",
        "newUsers": 120,
        "reportNewUsers": 80,
        "fbNewUsers": 96,
        "dauUsers": 560,
        "fbDauUsers": 510,
        "adRevenue": "320.500000",
        "adRevenueNow": "323.456000",
        "adRevenueDiff": "2.956000",
        "adRequests": 100000,
        "adMatchedRequests": 91000,
        "adImpressions": 86000,
        "adClicks": 4200,
        "adEcpm": "3.726744",
        "adCtr": "4.883721",
        "adMatchRate": "91.000000",
        "adShowRate": "94.505495",
        "impressionsPerUser": "153.571429",
        "arpu": "0.572321",
        "adSpendCost": "180.000000",
        "adSpendCpi": "1.500000",
        "adSpendCpc": "0.042857",
        "adSpendCpm": "2.093023",
        "trafficUsageMb": "20480.000000",
        "trafficCost": "3.200000",
        "totalCost": "183.200000",
        "trafficCostRatio": "0.017467",
        "profit": "137.300000",
        "roi": "1.749454",
        "updatedAt": "2026-06-05 10:00:00"
      }
    ],
    "summary": {
      "newUsers": 300,
      "reportNewUsers": 210,
      "fbNewUsers": 248,
      "dauUsers": 1350,
      "fbDauUsers": 1210,
      "adRevenue": "880.500000",
      "adRevenueNow": "900.000000",
      "adRevenueDiff": "19.500000",
      "adRequests": 280000,
      "adMatchedRequests": 255000,
      "adImpressions": 240000,
      "adClicks": 11800,
      "adEcpm": "3.668750",
      "adCtr": "4.916667",
      "adMatchRate": "91.071429",
      "adShowRate": "94.117647",
      "impressionsPerUser": "177.777778",
      "arpu": "0.652222",
      "adSpendCost": "500.000000",
      "adSpendCpi": "1.666667",
      "adSpendCpc": "0.042373",
      "adSpendCpm": "2.083333",
      "trafficUsageMb": "65536.000000",
      "trafficCost": "10.240000",
      "totalCost": "510.240000",
      "trafficCostRatio": "0.020070",
      "profit": "370.260000",
      "roi": "1.725597",
      "updatedAt": "2026-06-05 10:00:00"
    },
    "total": 1,
    "page": 1,
    "pageSize": 50,
    "dateFrom": "2026-06-01",
    "dateTo": "2026-06-05",
    "groupBy": ["reportDate", "projectCode"]
  }
}
```

### 返回说明

- `summary` 为当前筛选条件下的整体汇总，不受分页影响
- `summary` 与 `data`、`total`、`page`、`pageSize` 同级，位于 `data` 对象内部
- `summary.adRevenueNow` is the current revenue total for all filtered projects in `dateFrom ~ dateTo`, independent of pagination; it returns `null` when no project or current revenue can be matched
- `summary.adRevenueDiff = summary.adRevenueNow - summary.adRevenue`, formatted to 6 decimals; current revenue has no country dimension, so summary current revenue is aggregated only by project and date range even when `country` is filtered or grouped
- 当返回行包含唯一 `projectCode` 时，会附带 `adRevenueNow` 和 `adRevenueDiff`，字段位于返回行一级结构；无当前收益匹配数据时均返回 `null`
- `adRevenueNow` 来源于 `AdRevenueService::now()` 相同口径，即读取 `ad_revenue_daily_now` 并通过 `project_ad_platform_accounts` 映射到项目代号后聚合
- `adRevenueDiff = adRevenueNow - adRevenue`，结果保留 6 位小数
- 当返回行同时包含 `reportDate` 和 `projectCode` 时，`adRevenueNow` 按 `reportDate + projectCode` 匹配；当返回行只有 `projectCode` 且不含 `reportDate` 时，按本次请求 `dateFrom ~ dateTo` 范围内的该项目当前收益合计
- 当前收益不按 `country` 拆分；如果报表行包含国家维度，同一 `projectCode + reportDate` 下不同国家行会使用同一份 `adRevenueNow`，并分别与各行 `adRevenue` 计算 `adRevenueDiff`
- 当 `groupBy` 包含 `projectCode` 时，返回行会附带 `isLimited` 字段，表示上一完整 Asia/Shanghai 小时项目广告匹配率是否低于 `0.7`
- `isLimited` 来源于 `ad_revenue_hourly` 上一完整小时数据，通过 `project_ad_platform_accounts.ad_platform_account_id = ad_revenue_hourly.account_id` 映射到 `project_code`，不额外限定 `platform_code`、`source_platform` 或 `report_type`，并以 `SUM(matched_requests) / SUM(ad_requests)` 聚合判断；低于 `0.7` 为 `true`，大于等于 `0.7` 为 `false`
- 当上一完整小时 `SUM(ad_requests)=0` 时，如果上一完整小时项目聚合 `install_users > 0` 且上一完整小时所属日期、同项目代号在 `project_daily_aggregates` 中聚合后的当日 `ad_requests > 0`，`isLimited` 返回 `true`；否则返回 `null`
- `isLimited` 使用上一完整小时项目广告请求聚合结果计算，该聚合结果缓存 1 分钟
- 当返回行包含唯一 `projectCode` 时，会附带项目表元数据字段，例如 `adStatus`、`appPlatform`、`adspowerEnv`、`developerGmail`、`appName`、`packageName`、`domainInfoStatus`、`domainUrl` 等
- 当 `groupBy` 不包含 `projectCode` 时，聚合行无法确定唯一项目，不返回 `isLimited` 和项目表元数据字段
- CSV 导出保持固定列格式，不附加 `isLimited` 或项目表元数据字段
- 投放相关字段 `adSpendCost`、`adSpendCpi`、`adSpendCpc`、`adSpendCpm` 来源于 `ad_spend_platform_daily_reports` 聚合
- `adSpendCpc = 投放成本 / 投放点击数`，不使用广告收入侧 `adClicks`
- `adSpendCpm = 投放成本 * 1000 / 投放展示数`，不使用广告收入侧 `adImpressions`
- `totalCost = adSpendCost + trafficCost`
- `trafficCostRatio = trafficCost / totalCost`，当 `totalCost` 为 0 时返回 `null`
- `impressionsPerUser = adImpressions / dauUsers`
- `arpu = adRevenue / dauUsers`

## CSV 导出接口

### 请求参数

导出接口请求体与查询接口保持一致，但会忽略 `page` 和 `pageSize`，按当前筛选条件导出全量结果。

```json
{
  "dateFrom": "2026-06-01",
  "dateTo": "2026-06-05",
  "groupBy": ["projectCode"],
  "filters": {
    "projectCodes": ["A003"],
    "countries": ["US"],
    "adStatuses": ["running"],
    "appPlatforms": ["android"],
    "exclude": {
      "projectCodes": ["A002"],
      "countries": ["BR", "IN"]
    }
  },
  "orderBy": "adRevenue",
  "orderDirection": "desc"
}
```

### 返回说明

- 响应类型：`text/csv; charset=UTF-8`
- 文件名格式：`project_report_daily_YYYYMMDD_HHMMSS.csv`
- 编码：`UTF-8 with BOM`
- 返回内容为文件流，不走统一 JSON 响应结构

### CSV 列顺序

1. 日期
2. 项目编码
3. 国家
4. 新增用户
5. 上报新增用户
6. FB 新增用户
7. DAU
8. FB DAU
9. 广告收入
10. 广告请求数
11. 广告匹配请求数
12. 广告展示数
13. 广告点击数
14. eCPM
15. CTR
16. 匹配率
17. 展示率
18. 人均展示
19. ARPU
20. 投放成本
21. CPI
22. CPC
23. CPM
24. 流量用量 MB
25. 流量成本
26. 总成本
27. 流量成本占比
28. 利润
29. ROI
30. 更新时间

### 导出规则

- 导出复用查询接口的筛选、分组、排序逻辑
- 当 `groupBy` 不包含 `reportDate`、`projectCode` 或 `country` 时，对应 CSV 维度列留空
- 导出结果不包含 `summary` 汇总行
- 无数据时仍会返回仅包含表头的 CSV 文件

## 前端配合说明

- 导出按钮调用：`POST /api/v3/{secure_path}/report/project/export`
- 请求体直接复用当前项目日报查询表单条件，可以不传 `page`、`pageSize`
- Axios 示例：

```js
axios.post(url, payload, { responseType: 'blob' })
```

- 前端应优先从响应头 `Content-Disposition` 解析文件名
- 如果文件名解析失败，可回退为 `project_report_daily.csv`
- 该接口返回的是 CSV 文件流，前端不要按 `code`、`msg`、`data` 结构解析

## 查询缓存说明

- 项目日报 JSON 查询接口结果会按完整查询参数缓存 60 秒，包含 `data`、`summary`、分页信息、`isLimited`、`adRevenueNow`、`adRevenueDiff` 等返回字段。
- 缓存 key 会纳入 `dateFrom`、`dateTo`、`groupBy`、`filters`、`page`、`pageSize`、`orderBy`、`orderDirection`；筛选数组会去空、去重并排序，国家筛选统一转为大写。
- 管理端与应用端复用同一查询 Service，相同参数会共享缓存结果。
- CSV 导出接口不使用查询缓存，仍按当前筛选条件实时流式导出。

### hourly_status 限流状态码

当项目日报返回行包含唯一 `projectCode` 且已返回 `isLimited` 时，同时返回 `hourly_status`。该字段来源于上一完整 Asia/Shanghai 小时项目维度聚合指标，使用位运算 `|` 组合状态：

| 值 | 含义 |
| --- | --- |
| 0 | 正常，上一小时广告请求数大于 0 |
| 1 | 上一小时广告请求数为 0 |
| 2 | 上一小时用户新增为 0 |
| 4 | 当日用户新增为 0 |

当上一小时广告请求数为 0 时，会以 `1` 为基础，并按需使用 `| 2`、`| 4` 组合。例如 `3` 表示上一小时广告请求数为 0 且上一小时用户新增为 0；`5` 表示上一小时广告请求数为 0 且当日用户新增为 0；`7` 表示三种异常同时存在。CSV 导出不新增该字段。

## Top3 收益国家说明

- 项目日报 JSON 查询返回行新增 `topRevenueCountries` 字段，表示该行对应维度范围内广告收益最高的前 3 个国家。
- 字段结构为数组：`country` 为国家代码，`adRevenue` 为该国家收益，`ratio` 为该国家收益占当前行维度范围总收益的比例，金额和比例均保留 6 位小数。
- 计算来源为 `project_daily_aggregates.ad_revenue`，按当前返回行可确定的 `reportDate`、`projectCode`、`country` 维度以及请求 `dateFrom/dateTo`、筛选条件批量聚合；不对每一行单独查询。
- 当返回行包含 `country` 维度时，该字段只返回当前国家及其占比；当不包含 `country` 维度时返回当前日期/项目范围内收益 Top3 国家。
- 无收益或总收益小于等于 0 时返回空数组 `[]`。
- 该字段跟随项目日报 JSON 查询结果缓存 60 秒；CSV 导出不新增该列。
## 排除筛选说明

- `filters.exclude.projectCodes` 和 `filters.exclude.countries` 用于从当前筛选范围中排除指定项目或国家。
- 正向筛选与排除筛选同时存在时，服务端按“先包含、再排除”的交集口径处理。
- 日报 JSON、`summary`、`topRevenueCountries` 和 CSV 导出均使用相同排除筛选口径。