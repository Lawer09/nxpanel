# Project Aggregates 接口说明（前端对接）

本文补充 `project-aggregates` 相关接口，供前端报表页对接。

## 0. 基础说明

- 接口前缀：`/api/v3/{secure_path}`
- 鉴权：管理端登录态
- 统一返回：

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {}
}
```

---

## 1. 手动触发聚合（同步）

`POST /project-aggregates/aggregate`

请求体：

```json
{
  "startDate": "2026-04-01",
  "endDate": "2026-04-29"
}
```

返回 `data` 示例：

```json
{
  "success": true,
  "startDate": "2026-04-01",
  "endDate": "2026-04-29",
  "exitCode": 0,
  "output": "...artisan output..."
}
```

---

## 2. 手动触发聚合（异步）

`POST /project-aggregates/aggregate-async`

请求体：

```json
{
  "startDate": "2026-04-01",
  "endDate": "2026-04-29"
}
```

返回 `data` 示例：

```json
{
  "accepted": true,
  "triggerId": "5e4b8f89-1978-4d7a-a9e4-54fce57f2c07",
  "startDate": "2026-04-01",
  "endDate": "2026-04-29",
  "status": "queued"
}
```

---

## 3. 日报明细/分组查询

`GET /project-aggregates/daily`

### 3.1 Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | date | 是 | 开始日期 |
| endDate | date | 是 | 结束日期（>= startDate） |
| projectCode | string | 否 | 项目代号过滤 |
| adCountry | string | 否 | 广告国家过滤 |
| spendCountry | string | 否 | 投放国家过滤 |
| userCountry | string | 否 | 用户国家过滤（空值按 `OO` 处理） |
| groupBy | string[] | 否 | 分组维度数组：`reportDate/projectCode/adCountry/spendCountry/userCountry` |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 50，最大 200 |
| orderBy | string | 否 | 排序字段，见下方 |
| orderDir | string | 否 | `asc/desc`，默认 `desc` |

`orderBy` 可选值：

- 维度字段：`reportDate`、`projectCode`、`adCountry`、`spendCountry`、`userCountry`
- 指标字段：`revenue`、`adSpendCost`、`trafficCost`、`grossProfit`、`roi`、`cpi`、`updatedAt`

说明：

- 不传 `groupBy`：返回明细行（按 `project_daily_aggregates` 表行）
- 传 `groupBy`：返回聚合行（按分组汇总）

### 3.2 返回 `data` 示例

```json
{
  "data": [
    {
      "id": 123,
      "reportDate": "2026-04-29",
      "projectCode": "game_001",
      "adCountry": "US",
      "spendCountry": "US",
      "userCountry": "US",
      "reportNewUsers": 120,
      "dauUsers": 900,
      "registerNewUsers": 66,
      "revenue": "123.450000",
      "adRequests": 200000,
      "matchedRequests": 150000,
      "impressions": 140000,
      "clicks": 3500,
      "ecpm": "0.881786",
      "ctr": "2.500000",
      "matchRate": "75.000000",
      "showRate": "93.333333",
      "adSpendCost": "80.500000",
      "trafficUsageGb": "25.320000",
      "trafficCost": "4.051200",
      "grossProfit": "38.898800",
      "roi": "0.460244",
      "cpi": "0.670833",
      "fbEcpm": "0.881786",
      "updatedAt": "2026-04-29 12:10:00"
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 50
}
```

---

## 4. 汇总查询

`GET /project-aggregates/summary`

### 4.1 Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | date | 是 | 开始日期 |
| endDate | date | 是 | 结束日期 |
| projectCode | string | 否 | 项目代号过滤 |
| adCountry | string | 否 | 广告国家过滤 |
| spendCountry | string | 否 | 投放国家过滤 |
| userCountry | string | 否 | 用户国家过滤 |
| groupBy | string | 否 | `project/country/spendCountry/userCountry/date`，默认 `project` |

### 4.2 返回说明

- 返回为数组（非分页）
- 每项包含聚合指标 + 维度字段：
  - `groupBy=project` -> `projectCode`
  - `groupBy=country` -> `adCountry`
  - `groupBy=spendCountry` -> `spendCountry`
  - `groupBy=userCountry` -> `userCountry`
  - `groupBy=date` -> `date`

返回项示例：

```json
{
  "projectCode": "game_001",
  "reportNewUsers": 300,
  "dauUsers": 2500,
  "registerNewUsers": 180,
  "revenue": "450.120000",
  "adRequests": 600000,
  "matchedRequests": 430000,
  "impressions": 410000,
  "clicks": 11000,
  "ecpm": "1.097854",
  "ctr": "2.682927",
  "matchRate": "71.666667",
  "showRate": "95.348837",
  "adSpendCost": "290.000000",
  "trafficUsageGb": "71.000000",
  "trafficCost": "11.360000",
  "grossProfit": "148.760000",
  "roi": "0.491941",
  "cpi": "0.966667",
  "fbEcpm": "1.097854"
}
```

---

## 5. 趋势查询

`GET /project-aggregates/trend`

### 5.1 Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | date | 是 | 开始日期 |
| endDate | date | 是 | 结束日期 |
| projectCode | string | 否 | 项目代号过滤 |
| adCountry | string | 否 | 广告国家过滤 |
| spendCountry | string | 否 | 投放国家过滤 |
| userCountry | string | 否 | 用户国家过滤 |
| dimension | string | 否 | `day/month`，默认 `day` |

### 5.2 返回说明

- 返回为数组（非分页）
- 每项字段：
  - `time`
  - `reportNewUsers`、`dauUsers`、`registerNewUsers`
  - `revenue`、`adSpendCost`、`trafficUsageGb`、`trafficCost`、`grossProfit`
  - `roi`、`cpi`

返回项示例：

```json
{
  "time": "2026-04-29",
  "reportNewUsers": 120,
  "dauUsers": 900,
  "registerNewUsers": 66,
  "revenue": "123.450000",
  "adSpendCost": "80.500000",
  "trafficUsageGb": "25.320000",
  "trafficCost": "4.051200",
  "grossProfit": "38.898800",
  "roi": "0.460244",
  "cpi": "0.670833"
}
```

---

## 6. 前端对接注意事项

1. `userCountry` 统一建议传大写国家码；无国家场景使用 `OO`。
2. 报表筛选建议默认不传 `adCountry/userCountry`，避免过度过滤。
3. 日报页若需 pivot，可直接用 `daily + groupBy`，减少前端二次聚合。
4. 参数兼容：后端支持 `startdate/enddate/projectcode/adcountry/usercountry` 的小写形式自动映射。
