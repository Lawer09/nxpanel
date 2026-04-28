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
| adCountry | string | 否 | 广告国家 |
| page | int | 否 | 默认 `1` |
| pageSize | int | 否 | 默认 `50`，最大 `200` |
| orderBy | string | 否 | 默认 `reportDate` |
| orderDir | string | 否 | `asc` / `desc`，默认 `desc` |

### data 返回

```json
{
  "list": [
    {
      "id": 1,
      "reportDate": "2026-04-28",
      "projectCode": "A003",
      "adCountry": "US",
      "reportNewUsers": 36,
      "dauUsers": 220,
      "registerNewUsers": 28,
      "revenue": "344.340000",
      "adRequests": 120000,
      "matchedRequests": 108000,
      "impressions": 98000,
      "clicks": 5100,
      "ecpm": "3.513673",
      "ctr": "5.204082",
      "matchRate": "90.000000",
      "showRate": "90.740741",
      "adSpendCost": "210.000000",
      "trafficUsageGb": "52.300000",
      "trafficCost": "83.680000",
      "grossProfit": "50.660000",
      "roi": "0.172680",
      "cpi": "5.833333",
      "fbEcpm": "3.513673",
      "updatedAt": "2026-04-28 10:05:02"
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 50
}
```

---

## 3. 汇总查询

`GET /project-aggregates/summary`

### Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期 |
| endDate | string | 是 | 结束日期 |
| projectCode | string | 否 | 项目代号 |
| adCountry | string | 否 | 广告国家 |
| groupBy | string | 否 | `project` / `country` / `date`，默认 `project` |

### data 返回

```json
[
  {
    "projectCode": "A003",
    "reportNewUsers": 126,
    "dauUsers": 880,
    "registerNewUsers": 96,
    "revenue": "1298.120000",
    "adRequests": 520000,
    "matchedRequests": 468000,
    "impressions": 430000,
    "clicks": 22100,
    "ecpm": "3.018884",
    "ctr": "5.139535",
    "matchRate": "90.000000",
    "showRate": "91.880342",
    "adSpendCost": "860.000000",
    "trafficUsageGb": "201.500000",
    "trafficCost": "322.400000",
    "grossProfit": "115.720000",
    "roi": "0.220862",
    "cpi": "6.825397",
    "fbEcpm": "3.018884"
  }
]
```

---

## 4. 趋势查询

`GET /project-aggregates/trend`

### Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期 |
| endDate | string | 是 | 结束日期 |
| projectCode | string | 否 | 项目代号 |
| adCountry | string | 否 | 广告国家 |
| dimension | string | 否 | `day` / `month`，默认 `day` |

### data 返回

```json
[
  {
    "time": "2026-04-28",
    "reportNewUsers": 36,
    "dauUsers": 220,
    "registerNewUsers": 28,
    "revenue": "344.340000",
    "adSpendCost": "210.000000",
    "trafficUsageGb": "52.300000",
    "trafficCost": "83.680000",
    "grossProfit": "50.660000",
    "roi": "0.172680",
    "cpi": "5.833333"
  }
]
```

---

## 5. 字段说明（统一 CamelCase）

- `reportDate`: 日期
- `projectCode`: 项目代号
- `adCountry`: 广告国家
- `reportNewUsers`: 上报新增用户
- `dauUsers`: 日活用户
- `registerNewUsers`: 注册新增用户
- `revenue`: 广告收入
- `adRequests`: 请求数
- `matchedRequests`: 匹配数
- `impressions`: 展示量
- `clicks`: 点击量
- `ecpm`: eCPM
- `ctr`: CTR
- `matchRate`: 匹配率
- `showRate`: 展示率
- `adSpendCost`: 广告投放成本
- `trafficUsageGb`: 代理流量使用量（GB）
- `trafficCost`: 代理流量成本（`trafficUsageGb * 1.6`）
- `grossProfit`: 毛利（`revenue - adSpendCost - trafficCost`）
- `roi`: ROI（`grossProfit / (adSpendCost + trafficCost)`）
- `cpi`: CPI（`adSpendCost / reportNewUsers`）
- `fbEcpm`: eCPM（FB口径字段，占位与 `ecpm` 一致）
