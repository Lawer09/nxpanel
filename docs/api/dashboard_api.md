# Dashboard API

管理端路径前缀：`/api/v3/admin/{securePath}`

## 收益汇总

- 方法/路径：`POST /api/v3/admin/{securePath}/dashboard/income-summary`
- 控制器：`DashboardController::incomeSummary`
- 数据口径：复用项目日报汇总口径，数据来源为 `project_daily_aggregates` 及项目报表同款成本关联逻辑。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| appId | string | 否 | 用户 App ID；传入后通过 `project_user_app_map.app_id` 映射项目代号，只统计关联项目 |

### 返回字段

```json
{
  "today": {
    "dateFrom": "2026-07-28",
    "dateTo": "2026-07-28",
    "income": "0.000000",
    "revenue": "0.000000",
    "expense": "0.000000",
    "adRevenue": "0.000000",
    "adSpendCost": "0.000000",
    "trafficCost": "0.000000",
    "totalCost": "0.000000",
    "profit": "0.000000",
    "roi": "0.000000",
    "updatedAt": null
  },
  "month": {
    "dateFrom": "2026-07-01",
    "dateTo": "2026-07-28",
    "income": "0.000000",
    "revenue": "0.000000",
    "expense": "0.000000",
    "adRevenue": "0.000000",
    "adSpendCost": "0.000000",
    "trafficCost": "0.000000",
    "totalCost": "0.000000",
    "profit": "0.000000",
    "roi": "0.000000",
    "updatedAt": null
  }
}
```

- `income = profit`
- `revenue = adRevenue`
- `expense = totalCost`
- 当 `appId` 无项目映射时，返回零值，不报错。
