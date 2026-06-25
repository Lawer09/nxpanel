# Ad Revenue API 文档

本文档说明广告收入（Ad Revenue）相关接口，基于 `ad_revenue_daily` 表，提供明细、聚合、趋势、汇总、排行以及 APP 列表查询。

---

## 1. 明细查询

- **方法/路径**：`GET /api/v3/admin/{securePath}/ad-revenue/fetch`
- **控制器**：`AdRevenueController::fetch`
- **Request**：`AdRevenueFetch`
- **数据来源**：`ad_revenue_daily`

### 1.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 开始日期 `YYYY-MM-DD` |
| dateTo | string | 否 | 结束日期 `YYYY-MM-DD` |
| sourcePlatform | string | 否 | 来源平台 |
| accountId | int | 否 | 账号 ID |
| projectId | int | 否 | 项目 ID |
| providerAppId | string | 否 | 应用 ID |
| providerAdUnitId | string | 否 | 广告单元 ID |
| countryCode | string | 否 | 国家代码 |
| devicePlatform | string | 否 | 设备平台 |
| adFormat | string | 否 | 广告格式 |
| reportType | string | 否 | 报表类型 |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |
| orderBy | string | 否 | 排序字段，白名单：`report_date` / `impressions` / `clicks` / `estimated_earnings` / `ecpm` / `ad_requests` / `matched_requests` / `ctr` |
| orderDir | string | 否 | `asc` / `desc`，默认 `desc` |

### 1.2 返回字段（data[]）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 记录 ID |
| reportDate | string | 日期 |
| sourcePlatform | string | 来源平台 |
| accountId | int | 账号 ID |
| projectId | int/null | 项目 ID |
| providerAppId | string | 应用 ID |
| providerAdUnitId | string | 广告单元 ID |
| countryCode | string | 国家代码 |
| devicePlatform | string | 设备平台 |
| adFormat | string | 广告格式 |
| reportType | string | 报表类型 |
| adSourceCode | string | 广告源编码 |
| adRequests | int | 请求数 |
| matchedRequests | int | 匹配请求数 |
| impressions | int | 展示数 |
| clicks | int | 点击数 |
| estimatedEarnings | float | 预估收入 |
| ecpm | float | eCPM |
| ctr | float | CTR |
| matchRate | float | 匹配率 |
| showRate | float | 展示率 |
| rawHeaderJson | string/null | 原始 header JSON |
| rawRowJson | string/null | 原始 row JSON |
| syncTime | string/null | 同步时间 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

---

## 2. 聚合查询

- **方法/路径**：`POST /api/v3/admin/{securePath}/ad-revenue/aggregate`
- **控制器**：`AdRevenueController::aggregate`
- **Request**：`AdRevenueAggregate`
- **数据来源**：`ad_revenue_daily`

### 2.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| groupBy | string[] | 是 | 聚合维度，至少 1 个；可选值见下方 |
| dateFrom | string | 否 | 开始日期 |
| dateTo | string | 否 | 结束日期 |
| sourcePlatform | string | 否 | 筛选 |
| accountId | int | 否 | 筛选 |
| projectId | int | 否 | 筛选 |
| providerAppId | string | 否 | 筛选 |
| countryCode | string | 否 | 筛选 |
| devicePlatform | string | 否 | 筛选 |
| adFormat | string | 否 | 筛选 |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |
| orderBy | string | 否 | 排序字段 |
| orderDir | string | 否 | `asc` / `desc` |

**groupBy 可选值**：
`reportDate` / `sourcePlatform` / `accountId` / `providerAppId` / `providerAdUnitId` / `countryCode` / `devicePlatform` / `adFormat` / `reportType` / `adSourceCode`

### 2.2 返回字段（data[]）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| （维度字段） | - | 按 groupBy 动态出现，camelCase |
| adRequests | int | 请求数 SUM |
| matchedRequests | int | 匹配请求数 SUM |
| impressions | int | 展示数 SUM |
| clicks | int | 点击数 SUM |
| estimatedEarnings | float | 预估收入 SUM |
| ecpm | float | 收入/千次展示 |
| ctr | float | 点击率 |
| matchRate | float | 匹配率 |
| showRate | float | 展示率 |

---

## 3. 日期趋势

- **方法/路径**：`GET /api/v3/admin/{securePath}/ad-revenue/trend`
- **控制器**：`AdRevenueController::trend`
- **Request**：`AdRevenueTrend`

### 3.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 当前周期开始 |
| dateTo | string | 否 | 当前周期结束 |
| compareDateFrom | string | 否 | 对比周期开始（传此参数时返回 compare 数据） |
| compareDateTo | string | 否 | 对比周期结束 |
| sourcePlatform | string | 否 | 筛选 |
| accountId | int | 否 | 筛选 |
| projectId | int | 否 | 筛选 |
| providerAppId | string | 否 | 筛选 |
| countryCode | string | 否 | 筛选 |
| devicePlatform | string | 否 | 筛选 |
| adFormat | string | 否 | 筛选 |

### 3.2 返回结构

```json
{
  "current": [ ... ],
  "compare": [ ... ]  // 仅传 compareDateFrom/compareDateTo 时存在
}
```

每个数组项字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| reportDate | string | 日期 |
| adRequests | int | 请求数 |
| matchedRequests | int | 匹配请求数 |
| impressions | int | 展示数 |
| clicks | int | 点击数 |
| estimatedEarnings | float | 预估收入 |
| ecpm | float | eCPM |
| ctr | float | CTR |

---

## 4. 汇总概览

- **方法/路径**：`GET /api/v3/admin/{securePath}/ad-revenue/summary`
- **控制器**：`AdRevenueController::summary`
- **Request**：`AdRevenueSummary`

### 4.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 开始日期 |
| dateTo | string | 否 | 结束日期 |
| sourcePlatform | string | 否 | 筛选 |
| accountId | int | 否 | 筛选 |
| projectId | int | 否 | 筛选 |

### 4.2 返回字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| adRequests | int | 总请求数 |
| matchedRequests | int | 总匹配请求数 |
| impressions | int | 总展示数 |
| clicks | int | 总点击数 |
| estimatedEarnings | float | 总预估收入 |
| ecpm | float | 整体 eCPM |
| ctr | float | 整体 CTR |
| matchRate | float | 整体匹配率 |
| accountCount | int | 去重账号数 |
| appCount | int | 去重应用数 |
| dayCount | int | 去重天数 |

---

## 5. Top 排行

- **方法/路径**：`POST /api/v3/admin/{securePath}/ad-revenue/top-rank`
- **控制器**：`AdRevenueController::topRank`
- **Request**：`AdRevenueTopRank`

### 5.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| rankBy | string | 是 | 排行维度：`app` / `ad_unit` / `country` / `account` / `platform` |
| metric | string | 否 | 指标：`estimated_earnings`（默认）/ `impressions` / `clicks` / `ecpm` |
| dateFrom | string | 否 | 筛选 |
| dateTo | string | 否 | 筛选 |
| sourcePlatform | string | 否 | 筛选 |
| accountId | int | 否 | 筛选 |
| projectId | int | 否 | 筛选 |
| limit | int | 否 | 返回条数，默认 20，最大 100 |

### 5.2 返回字段（data[]）

按 `rankBy` 动态返回维度字段。以 `rankBy=app` 为例：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| providerAppId | string | 应用 ID |
| providerAppName | string | 应用名称 |
| impressions | int | 展示数 |
| clicks | int | 点击数 |
| estimatedEarnings | float | 预估收入 |
| （metric） | 同 metric 类型 | 排序指标的值 |

---

## 6. APP 列表查询

- **方法/路径**：`GET /api/v3/admin/{securePath}/ad-revenue/apps`
- **控制器**：`AdRevenueController::fetchApps`
- **数据来源**：`ad_platform_app`（左关联 `ad_platform_account` 取账号名称）

### 6.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| sourcePlatform | string | 否 | 来源平台筛选 |
| accountId | int | 否 | 账号 ID 筛选 |
| keyword | string | 否 | 模糊搜索（匹配 providerAppName / appStoreId / providerAppId） |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |

### 6.2 返回结构

```json
{
  "data": [
    {
      "id": 1,
      "sourcePlatform": "google",
      "accountId": 1,
      "accountName": "Google Ads Account",
      "accountLabel": "A001",
      "providerAppId": "com.example.app",
      "providerAppName": "Example App",
      "devicePlatform": "android",
      "appStoreId": "123456789",
      "appApprovalState": "approved",
      "createdAt": "2026-05-12T00:00:00.000Z",
      "updatedAt": "2026-05-12T00:00:00.000Z"
    }
  ],
  "total": 0,
  "page": 1,
  "pageSize": 20
}
```

### 6.3 data[] 字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 记录 ID |
| sourcePlatform | string | 来源平台 |
| accountId | int | 账号 ID |
| accountName | string/null | 账号名称（来自 `ad_platform_account`） |
| accountLabel | string/null | 账号Label（来自 `ad_platform_account`） |
| providerAppId | string | 应用 ID |
| providerAppName | string | 应用名称 |
| devicePlatform | string | 设备平台 |
| appStoreId | string | 应用商店 ID |
| appApprovalState | string | 审核状态 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

---

## 通用说明

- 路径中的 `{securePath}` 由 `admin_setting('secure_path', ...)` 动态生成
- 所有请求/返回参数均为 **camelCase**
- 分页接口统一返回 `data` / `total` / `page` / `pageSize`
- 汇总概览接口返回 null 时表示无数据
- 趋势接口的 `compare` 字段仅在传入对比日期参数时出现

---

## 7. 当前收益与日报收益差值查询

- **方法/路径**：`POST /api/v3/admin/{securePath}/ad-revenue/now-diff`
- **控制器**：`AdRevenueController::nowDiff`
- **Request**：`AdRevenueNowDiffRequest`
- **数据来源**：以 `ad_revenue_daily_now` 为主表，left join `ad_revenue_daily`

### 7.1 用途

按 `account_id + report_date + provider_app_id + device_platform + source_platform + report_type` 对齐当前收益快照表与正式日报表，返回两边收益和 `estimatedEarningsDiff`，用于检查近 30 天当前表回填/同步后与日报表之间的收益差异。

只返回 `ad_revenue_daily_now` 中存在的维度行；如果 `ad_revenue_daily` 缺失对应行，`dailyEstimatedEarnings` 按 `0.000000` 参与差值计算。

### 7.2 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 开始日期，格式 `YYYY-MM-DD` |
| dateTo | string | 否 | 结束日期，格式 `YYYY-MM-DD`，不能早于 `dateFrom` |
| sourcePlatform | string | 否 | 广告平台来源 |
| reportType | string | 否 | 报表类型 |
| accountId | int | 否 | 广告平台账号 ID |
| providerAppId | string | 否 | 广告平台应用 ID |
| devicePlatform | string | 否 | 设备平台 |
| projectCode | string | 否 | 项目代号；传入后仅返回可映射到该项目代号的数据 |
| page | int | 否 | 默认 `1` |
| pageSize | int | 否 | 默认 `20`，最大 `200` |
| orderBy | string | 否 | 默认 `reportDate`；支持 `reportDate` / `accountId` / `providerAppId` / `devicePlatform` / `projectCode` / `nowEstimatedEarnings` / `dailyEstimatedEarnings` / `estimatedEarningsDiff` / `nowUpdatedAt` / `dailyUpdatedAt` |
| orderDir | string | 否 | `asc` / `desc`，默认 `desc` |

请求示例：

```json
{
  "dateFrom": "2026-06-01",
  "dateTo": "2026-06-25",
  "accountId": 1,
  "providerAppId": "ca-app-pub-xxx~yyy",
  "devicePlatform": "ANDROID",
  "projectCode": "A003",
  "page": 1,
  "pageSize": 20,
  "orderBy": "estimatedEarningsDiff",
  "orderDir": "desc"
}
```

### 7.3 项目代号映射

项目代号来自 `project_ad_platform_accounts`：

- `platform_code = source_platform`
- `ad_platform_account_id = account_id`
- `enabled = 1`
- APP 级映射优先：`bind_type != account` 且 `external_app_id = provider_app_id`
- 无 APP 级映射时使用账号级映射：`bind_type = account`
- 未映射时 `projectCode = null`
- 传入 `projectCode` 过滤时，未映射数据不会返回

### 7.4 返回字段

分页结构为 `data` / `total` / `page` / `pageSize`。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| projectCode | string/null | 映射到的项目代号 |
| accountId | int | 广告平台账号 ID |
| reportDate | string | 报表日期 |
| providerAppId | string | 广告平台应用 ID |
| devicePlatform | string | 设备平台 |
| sourcePlatform | string | 广告平台来源 |
| reportType | string | 报表类型 |
| nowEstimatedEarnings | string | 当前表收益，6 位小数 |
| dailyEstimatedEarnings | string | 日报表收益，6 位小数 |
| estimatedEarningsDiff | string | `nowEstimatedEarnings - dailyEstimatedEarnings`，仅返回收益差值 |
| nowUpdatedAt | string/null | 当前表最新更新时间 |
| dailyUpdatedAt | string/null | 日报表最新更新时间 |

返回示例：

```json
{
  "data": [
    {
      "projectCode": "A003",
      "accountId": 1,
      "reportDate": "2026-06-20",
      "providerAppId": "ca-app-pub-xxx~yyy",
      "devicePlatform": "ANDROID",
      "sourcePlatform": "admob",
      "reportType": "network",
      "nowEstimatedEarnings": "123.456000",
      "dailyEstimatedEarnings": "120.000000",
      "estimatedEarningsDiff": "3.456000",
      "nowUpdatedAt": "2026-06-25 10:00:00",
      "dailyUpdatedAt": "2026-06-24 03:00:00"
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 20
}
```

---

## 8. 当前收益查询

- **方法/路径**：`POST /api/v3/admin/{securePath}/ad-revenue/now`
- **控制器**：`AdRevenueController::now`
- **Request**：`AdRevenueNowRequest`
- **数据来源**：仅查询 `ad_revenue_daily_now`，不查询 `ad_revenue_daily`

### 8.1 用途

按 `account_id + report_date + provider_app_id + device_platform + source_platform + report_type` 聚合当前收益表，返回当前收益和当前表更新时间。该接口不计算日报差值，不返回 `dailyEstimatedEarnings`、`estimatedEarningsDiff` 或 `dailyUpdatedAt`。

### 8.2 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 开始日期，格式 `YYYY-MM-DD` |
| dateTo | string | 否 | 结束日期，格式 `YYYY-MM-DD`，不能早于 `dateFrom` |
| sourcePlatform | string | 否 | 广告平台来源 |
| reportType | string | 否 | 报表类型 |
| accountId | int | 否 | 广告平台账号 ID |
| providerAppId | string | 否 | 广告平台应用 ID |
| devicePlatform | string | 否 | 设备平台 |
| projectCode | string | 否 | 项目代号；传入后仅返回可映射到该项目代号的数据 |
| page | int | 否 | 默认 `1` |
| pageSize | int | 否 | 默认 `20`，最大 `200` |
| orderBy | string | 否 | 默认 `reportDate`；支持 `reportDate` / `accountId` / `providerAppId` / `devicePlatform` / `projectCode` / `nowEstimatedEarnings` / `nowUpdatedAt` |
| orderDir | string | 否 | `asc` / `desc`，默认 `desc` |

### 8.3 项目代号映射

项目代号来自 `project_ad_platform_accounts`，口径与 `now-diff` 一致：

- `platform_code = source_platform`
- `ad_platform_account_id = account_id`
- `enabled = 1`
- APP 级映射优先：`bind_type != account` 且 `external_app_id = provider_app_id`
- 无 APP 级映射时使用账号级映射：`bind_type = account`
- 未映射时 `projectCode = null`
- 传入 `projectCode` 过滤时，未映射数据不会返回

### 8.4 返回字段

分页结构为 `data` / `total` / `page` / `pageSize`。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| projectCode | string/null | 映射到的项目代号 |
| accountId | int | 广告平台账号 ID |
| reportDate | string | 报表日期 |
| providerAppId | string | 广告平台应用 ID |
| devicePlatform | string | 设备平台 |
| sourcePlatform | string | 广告平台来源 |
| reportType | string | 报表类型 |
| nowEstimatedEarnings | string | 当前表收益，6 位小数 |
| nowUpdatedAt | string/null | 当前表最新更新时间 |

返回示例：

```json
{
  "data": [
    {
      "projectCode": "A003",
      "accountId": 1,
      "reportDate": "2026-06-20",
      "providerAppId": "ca-app-pub-xxx~yyy",
      "devicePlatform": "ANDROID",
      "sourcePlatform": "admob",
      "reportType": "network",
      "nowEstimatedEarnings": "123.456000",
      "nowUpdatedAt": "2026-06-25 10:00:00"
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 20
}
```
