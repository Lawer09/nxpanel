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
    "countries": ["US"]
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
        "country": "US",
        "newUsers": 120,
        "reportNewUsers": 80,
        "fbNewUsers": 96,
        "dauUsers": 560,
        "fbDauUsers": 510,
        "adRevenue": "320.500000",
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
- `totalCost = adSpendCost + trafficCost`
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
    "countries": ["US"]
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
27. 利润
28. ROI
29. 更新时间

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
