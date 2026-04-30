# 项目聚合报表查询接口（CamelCase）

本文为前端对接「项目日聚合表 `project_daily_aggregates`」接口说明，参数与返回统一使用驼峰写法。

## 1. 基础信息

- 前缀：`/api/v3/{securePath}`
- 返回格式：

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {}
}
```

---

## 2. 日报明细查询（主接口）

`GET /project-aggregates/daily`

### Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期，`YYYY-MM-DD` |
| endDate | string | 是 | 结束日期，`YYYY-MM-DD` |
| projectCode | string | 否 | 项目代号 |
| country | string | 否 | 国家（空值统一按 `XX` 处理） |
| groupBy | string[] | 否 | 按维度聚合，支持 `reportDate` / `projectCode` / `country`，默认明细 |
| page | int | 否 | 默认 `1` |
| pageSize | int | 否 | 默认 `50`，最大 `200` |
| orderBy | string | 否 | 默认 `reportDate`，支持：`reportDate` / `projectCode` / `country` / `adRevenue` / `adSpendCost` / `trafficCost` / `profit` / `roi` / `adSpendCpi` / `updatedAt` |
| orderDir | string | 否 | `asc` / `desc`，默认 `desc` |

`groupBy` 定义：

- 不传或传空数组：明细（按 `reportDate + projectCode + country` 原始粒度）
- 传数组：按数组中的维度组合聚合，例如：
  - `['reportDate', 'projectCode']`
  - `['reportDate', 'projectCode', 'country']`

说明：

- 仅支持 `groupBy`（驼峰）参数名
- 仅支持数组形式 `groupBy`

### data 返回

```json
{
  "data": [
    {
      "id": 1,
      "reportDate": "2026-04-28",
      "projectCode": "A003",
      "country": "US",
      "dauUsers": 220,
      "newUsers": 36,
      "adRevenue": "344.340000",
      "adRequests": 120000,
      "adMatchedRequests": 108000,
      "adImpressions": 98000,
      "adClicks": 5100,
      "adEcpm": "3.513673",
      "adCtr": "5.204082",
      "adMatchRate": "90.000000",
      "adShowRate": "90.740741",
      "adSpendCost": "210.000000",
      "adSpendCpi": "5.833333",
      "adSpendCpc": "0.041176",
      "adSpendCpm": "2.142857",
      "trafficUsageMb": "53555.200000",
      "trafficCost": "8.368000",
      "profit": "125.972000",
      "roi": "1.580370",
      "updatedAt": "2026-04-28 10:05:02"
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 50
}
```

---

## 2.1 手动触发聚合（日期范围）

`POST /project-aggregates/aggregate`

### Body 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期，`YYYY-MM-DD` |
| endDate | string | 是 | 结束日期，`YYYY-MM-DD` |

### 示例请求

```json
{
  "startDate": "2026-04-01",
  "endDate": "2026-04-28"
}
```

### data 返回

```json
{
  "success": true,
  "startDate": "2026-04-01",
  "endDate": "2026-04-28",
  "exitCode": 0,
  "output": "Start aggregating project daily data..."
}
```

说明：该接口会同步调用 `project:aggregate-daily --start-date --end-date`。

---

## 2.2 手动触发聚合（异步）

`POST /project-aggregates/aggregate-async`

### Body 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期，`YYYY-MM-DD` |
| endDate | string | 是 | 结束日期，`YYYY-MM-DD` |

### 示例请求

```json
{
  "startDate": "2026-04-01",
  "endDate": "2026-04-28"
}
```

### data 返回

```json
{
  "accepted": true,
  "triggerId": "7f517a8a-7f56-4d4f-a0cf-1649bc1f4af9",
  "startDate": "2026-04-01",
  "endDate": "2026-04-28",
  "status": "queued"
}
```

说明：
- 该接口仅投递队列任务并立即返回
- 需确保队列消费者已启动（如 `php artisan queue:work`）
- 任务执行日志可通过 `triggerId` 在日志中检索

---

## 3. 汇总查询

`GET /project-aggregates/summary`

### Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期，`YYYY-MM-DD` |
| endDate | string | 是 | 结束日期，`YYYY-MM-DD` |
| projectCode | string | 否 | 项目代号 |
| country | string | 否 | 国家 |
| groupBy | string | 否 | 聚合维度：`project` / `country` / `date`，默认 `project` |

### data 返回

```json
[
  {
    "projectCode": "A003",
    "newUsers": 380,
    "dauUsers": 2450,
    "adRevenue": "5230.330000",
    "adRequests": 1860000,
    "adMatchedRequests": 1670000,
    "adImpressions": 1490000,
    "adClicks": 78800,
    "adEcpm": "3.510289",
    "adCtr": "5.288591",
    "adMatchRate": "89.784946",
    "adShowRate": "89.221557",
    "adSpendCost": "3100.000000",
    "adSpendCpi": "8.157895",
    "adSpendCpc": "0.039340",
    "adSpendCpm": "2.080537",
    "trafficUsageMb": "782344.120000",
    "trafficCost": "122.241269",
    "profit": "2008.088731",
    "roi": "1.624269"
  }
]
```

---

## 4. 趋势查询

`GET /project-aggregates/trend`

### Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期，`YYYY-MM-DD` |
| endDate | string | 是 | 结束日期，`YYYY-MM-DD` |
| projectCode | string | 否 | 项目代号 |
| country | string | 否 | 国家 |
| dimension | string | 否 | 时间维度：`day` / `month`，默认 `day` |

### data 返回

```json
[
  {
    "time": "2026-04-28",
    "newUsers": 36,
    "dauUsers": 220,
    "adRevenue": "344.340000",
    "adSpendCost": "210.000000",
    "adSpendCpi": "5.833333",
    "trafficUsageMb": "53555.200000",
    "trafficCost": "8.368000",
    "profit": "125.972000",
    "roi": "1.580370"
  }
]
```

---

## 5. 字段说明（统一 CamelCase）

- `reportDate`: 日期
- `projectCode`: 项目代号
- `country`: 国家（空值统一归一为 `XX`）
- `dauUsers`: 活跃用户数
- `newUsers`: 新增用户数
- `adRevenue`: 广告收入
- `adRequests`: 请求数
- `adMatchedRequests`: 匹配数
- `adImpressions`: 展示量
- `adClicks`: 点击量
- `adEcpm`: eCPM
- `adCtr`: CTR
- `adMatchRate`: 匹配率
- `adShowRate`: 展示率
- `adSpendCost`: 广告投放成本
- `adSpendCpi`: CPI（`adSpendCost / newUsers`）
- `adSpendCpc`: CPC（`adSpendCost / adClicks`）
- `adSpendCpm`: CPM（`adSpendCost * 1000 / adImpressions`）
- `trafficUsageMb`: 代理流量使用量（MB）
- `trafficCost`: 代理流量成本（`trafficUsageMb * 0.16 / 1024`）
- `profit`: 毛利（`adRevenue - adSpendCost - trafficCost`）
- `roi`: ROI（`adRevenue / (adSpendCost + trafficCost)`）
- `updatedAt`: 更新时间
