# Application Route API（V3）

本文档描述通过应用身份访问的 V3 管理能力接口。

## 基本说明

- 路由前缀：`/api/v3/application`
- 鉴权中间件：`app` + `log`

## 应用鉴权

优先使用请求头传递：

- `X-App-Id`：应用 ID
- `X-App-Token`：应用 Token

兼容请求参数：

- `appId` / `app_id`
- `appToken` / `app_token`

鉴权失败返回 403。

---

## 报表查询

### 1) 项目报表查询

- 方法/路径：`POST /api/v3/application/report/project/query`
- 对应控制器方法：`ReportController::queryProjectReport`
- 鉴权：应用身份（`app` 中间件）

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 开始日期，默认昨天 |
| dateTo | string | 否 | 结束日期，默认今天 |
| hourFrom | int | 否 | 开始小时 0-23 |
| hourTo | int | 否 | 结束小时 0-23 |
| groupBy | string[] | 否 | 维度分组：`reportDate`/`hour`/`projectCode`/`country` |
| filters.projectCodes | string[] | 否 | 项目代号过滤 |
| filters.countries | string[] | 否 | 国家过滤 |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 50，最大 200 |
| orderBy | string | 否 | `reportDate`/`hour`/`projectCode`/`country`/`installUsers`/`hourlyDauUsers`/`dailyDauUsers`/`adRevenue`/`adSpendCost`/`ros`/`id`/`updatedAt` |
| orderDirection | string | 否 | `asc` / `desc` |

---

## 1. 表名与维度

- 表名：`project_report_hourly`
- 维度：`date + hour + project_code + country`
- 国家归一规则与项目聚合保持一致：空值统一为 `XX`，并转大写

---

## 2. 字段定义

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | bigint | 主键 |
| date | date | 报表日期 |
| hour | tinyint | 小时（0-23） |
| project_code | varchar(100) | 项目代号 |
| country | varchar(50) | 国家（空值归一 `XX`） |
| install_users | int unsigned | 安装数（全生命周期首次上报小时） |
| hourly_dau_users | int unsigned | 小时活跃用户数（去重用户） |
| daily_dau_users | int unsigned | 日活跃用户数（同日同项目同国家去重） |
| ad_revenue | decimal(20,6) | 按小时分配后的广告收益 |
| ad_spend_cost | decimal(20,6) | 按小时分配后的广告花费 |
| ros | decimal(20,6) nullable | 收益转化指标 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |