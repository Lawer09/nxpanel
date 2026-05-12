# project_report API 文档

本文档基于当前代码实现，说明项目聚合报表查询接口（支持筛选、聚合、排序、分页）。

## 1. 查询接口

- 方法/路径：`POST /api/v3/admin/report/project/query`
- 控制器：`App\Http\Controllers\V3\Admin\ProjectAggregateController::queryDaily`
- Request：`App\Http\Requests\Admin\ProjectAggregateDailyQueryRequest`
- 数据来源表：`project_daily_aggregates`

## 1.1 请求参数

- `dateFrom` `string|null`，开始日期（`YYYY-MM-DD`），默认：当前时间 - 1 天
- `dateTo` `string|null`，结束日期（`YYYY-MM-DD`），默认：当前时间
- `groupBy` `string[]|null`，可选维度：
  - `reportDate`
  - `projectCode`
  - `country`
- `filters` `object|null`
  - `filters.projectCodes` `string[]|null`
  - `filters.countries` `string[]|null`（入参会统一转大写后过滤）
- `page` `int|null`，默认 `1`
- `pageSize` `int|null`，默认 `50`，范围 `1-200`
- `orderBy` `string|null`，可选：
  - 维度字段：`reportDate/projectCode/country`
  - 指标字段：`newUsers/reportNewUsers/dauUsers/adRevenue/adRequests/adMatchedRequests/adImpressions/adClicks/adEcpm/adCtr/adMatchRate/adShowRate/adSpendCost/adSpendCpi/adSpendCpc/adSpendCpm/trafficUsageMb/trafficCost/totalCost/impressionsPerUser/arpu/profit/roi`
  - 其它：`id/updatedAt`
- `orderDirection` `string|null`，`asc|desc`，默认 `desc`

## 1.2 聚合与排序规则

- 不传 `groupBy`：返回明细行（按日+项目+国家的存量聚合明细记录）
- 传 `groupBy`：按维度聚合，指标字段使用 `SUM`，衍生指标按公式计算
- 不传 `groupBy` 时默认排序：`reportDate desc`
- 传 `groupBy` 时默认排序：`adRevenue desc`
- 排序字段会进行白名单校验，不在允许范围内会回退到默认排序

## 1.3 返回字段

顶层：

- `data` `array<object>`
- `total` `int`
- `page` `int`
- `pageSize` `int`
- `dateFrom` `string`
- `dateTo` `string`
- `groupBy` `array<string>`

`data[]` 常见字段（按是否分组、分组维度动态出现）：

- 维度字段：`reportDate/projectCode/country`
- 指标字段：
  - `newUsers` `int`
  - `reportNewUsers` `int`
  - `dauUsers` `int`
  - `adRevenue` `string|null`（6位小数）
  - `adRequests` `int`
  - `adMatchedRequests` `int`
  - `adImpressions` `int`
  - `adClicks` `int`
  - `adEcpm` `string|null`（6位小数）
  - `adCtr` `string|null`（6位小数）
  - `adMatchRate` `string|null`（6位小数）
  - `adShowRate` `string|null`（6位小数）
  - `adSpendCost` `string|null`（6位小数）
  - `adSpendCpi` `string|null`（6位小数）
  - `adSpendCpc` `string|null`（6位小数）
  - `adSpendCpm` `string|null`（6位小数）
  - `trafficUsageMb` `string|null`（6位小数）
  - `trafficCost` `string|null`（6位小数）
  - `totalCost` `string|null`（6位小数，`adSpendCost + trafficCost`）
  - `impressionsPerUser` `string|null`（6位小数，`adImpressions / dauUsers`）
  - `arpu` `string|null`（6位小数，`adRevenue / dauUsers`）
  - `profit` `string|null`（6位小数）
  - `roi` `string|null`（6位小数）
- 其它字段：`id`（仅明细行可用）、`updatedAt`

## 2. 示例请求

### 2.1 明细查询

```json
{
  "dateFrom": "2026-05-01",
  "dateTo": "2026-05-07",
  "filters": {
    "projectCodes": ["PJT_A", "PJT_B"],
    "countries": ["us", "jp"]
  },
  "page": 1,
  "pageSize": 50,
  "orderBy": "reportDate",
  "orderDirection": "desc"
}
```

### 2.2 聚合查询（按项目+国家）

```json
{
  "dateFrom": "2026-05-01",
  "dateTo": "2026-05-07",
  "groupBy": ["projectCode", "country"],
  "filters": {
    "projectCodes": ["PJT_A"]
  },
  "page": 1,
  "pageSize": 100,
  "orderBy": "adRevenue",
  "orderDirection": "desc"
}
```

## 3. 前端字段映射表

下表用于管理端表格直连接口字段，包含展示名、字段名、类型和单位/说明。

| 中文名 | 字段名 | 类型 | 单位/说明 |
| --- | --- | --- | --- |
| 日期 | `reportDate` | string/null | `YYYY-MM-DD` |
| 项目编码 | `projectCode` | string/null | 维度字段 |
| 国家 | `country` | string/null | ISO 国家码（接口过滤时统一大写） |
| 新增用户 | `newUsers` | int | 人 |
| 上报新增用户 | `reportNewUsers` | int | 人 |
| DAU 用户 | `dauUsers` | int | 人 |
| 广告收入 | `adRevenue` | string/null | 金额，6 位小数 |
| 广告请求数 | `adRequests` | int | 次 |
| 广告匹配请求数 | `adMatchedRequests` | int | 次 |
| 广告展示数 | `adImpressions` | int | 次 |
| 广告点击数 | `adClicks` | int | 次 |
| 广告 eCPM | `adEcpm` | string/null | 收入/千次展示，6 位小数 |
| 广告 CTR | `adCtr` | string/null | 百分比值（0-100），6 位小数 |
| 广告匹配率 | `adMatchRate` | string/null | 百分比值（0-100），6 位小数 |
| 广告展示率 | `adShowRate` | string/null | 百分比值（0-100），6 位小数 |
| 广告花费 | `adSpendCost` | string/null | 金额，6 位小数 |
| 花费 CPI | `adSpendCpi` | string/null | 金额/新增用户，6 位小数 |
| 花费 CPC | `adSpendCpc` | string/null | 金额/点击，6 位小数 |
| 花费 CPM | `adSpendCpm` | string/null | 金额/千次展示，6 位小数 |
| 流量使用量 | `trafficUsageMb` | string/null | MB，6 位小数 |
| 流量成本 | `trafficCost` | string/null | 金额，6 位小数 |
| 总花费 | `totalCost` | string/null | 金额（adSpendCost + trafficCost），6 位小数 |
| 人均展示 | `impressionsPerUser` | string/null | 展示数/DAU，6 位小数 |
| 每用户平均收入 | `arpu` | string/null | 广告收入/DAU，6 位小数 |
| 利润 | `profit` | string/null | 金额，6 位小数 |
| ROI | `roi` | string/null | 收入/总成本，6 位小数 |
| 记录 ID | `id` | int/null | 仅明细查询通常有值 |
| 更新时间 | `updatedAt` | string/null | 数据更新时间 |

前端展示建议：

- 金额类字段：`adRevenue/adSpendCost/trafficCost/totalCost/profit/adSpendCpi/adSpendCpc/adSpendCpm` 建议统一货币格式，并保留 2 位小数。
- 比例类字段：`adCtr/adMatchRate/adShowRate` 建议展示为百分比并保留 2 位小数。
- `roi` 建议按百分比展示：`roiPercent = roi * 100`，并保留 2 位小数（例如 `1.234567 -> 123.46%`）。
- 空值处理：接口分母为 0 时返回 `null`，前端可展示为 `--`。

筛选交互建议：

- 国家筛选改为手动输入（不使用下拉）。
- 输入格式建议支持逗号分隔（如 `US,JP,BR`），前端拆分后传 `filters.countries` 数组。
- 前端提交前建议统一转大写并去除空格；后端也会做大写归一化。
