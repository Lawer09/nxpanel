# 项目报表查询接口

## 基本说明

- 管理端路径：`POST /api/v3/{secure_path}/report/project/query`
- 应用端路径：`POST /api/v3/application/report/project/query`
- 控制器：`App\Http\Controllers\V3\Admin\ReportController::queryProjectReport`
- Service：`App\Services\ProjectReportService::queryDaily`

两条路径共用同一套查询逻辑和返回结构。

## 请求参数

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

## 返回示例

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

## 返回说明

- `summary` 为当前筛选条件下的整体验证汇总，不受分页影响。
- `summary` 与 `page`、`pageSize`、`total` 同级，位于 `data` 对象内部。
- 其他报表接口当前没有新增 `summary` 字段，本次只有项目日报查询接口支持。
- `totalCost = adSpendCost + trafficCost`
- `impressionsPerUser = adImpressions / dauUsers`
- `arpu = adRevenue / dauUsers`

## 实现说明

- Controller 只负责接收请求和返回响应，具体查询逻辑已经下沉到 `ProjectReportService`。
- `ReportController` 中其他原本直接查询数据库的报表接口，也已统一改为调用 Service。
