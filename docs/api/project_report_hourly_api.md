# Project Report Hourly 口径说明

本文档说明 `project_report_hourly` 表字段及计算口径。该表由 `project:aggregate-daily` 在每日聚合后同步产出。

## 查询接口

- 方法/路径：`POST /api/v3/admin/{securePath}/report/project/hourly/query`
- 控制器：`ReportController::queryProjectReportHourly`
- Request：`ProjectReportHourlyQueryRequest`

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
| orderBy | string | 否 | `reportDate`/`hour`/`projectCode`/`country`/`installUsers`/`dauUsers`/`adRevenue`/`adSpendCost`/`ros`/`id`/`updatedAt` |
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
| dau_users | int unsigned | 小时活跃用户数（去重用户） |
| ad_revenue | decimal(20,6) | 按小时分配后的广告收益 |
| ad_spend_cost | decimal(20,6) | 按小时分配后的广告花费 |
| ros | decimal(20,6) nullable | 收益转化指标（查询接口按实时口径计算返回） |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

---

## 3. 数据来源

- AdRevenue（日级）：复用项目日聚合口径
- AdSpend（日级）：复用项目日聚合口径
- 用户活跃/新增（小时级）：`v3_user_report_count`
- 项目映射：`project_user_app_map`（`enabled = 1`）

---

## 4. 计算口径

1) 小时活跃

- `dau_users = COUNT(DISTINCT user_id)`
- 维度：`date + hour + project_code + country`

2) 日活跃

- `daily_dau_users = COUNT(DISTINCT user_id)`
- 维度：`date + project_code + country`

3) 安装（与现有新增口径一致）

- `install_users` 取用户在 `v3_user_report_count` 的**全生命周期首次上报小时**
- 若首次上报落在当前 `date + hour`，则计入该小时

4) 收益/花费按小时分配

- `hourly_ratio = dau_users / daily_dau_users`
- `ad_revenue = daily_ad_revenue * hourly_ratio`
- `ad_spend_cost = daily_ad_spend_cost * hourly_ratio`
- 若 `daily_dau_users = 0`，则 `hourly_ratio = 0`

5) ROS

- `ros = (ad_revenue * (install_users / dau_users)) / ad_spend_cost`
- 若 `dau_users = 0` 或 `ad_spend_cost = 0`，则 `ros = null`
- 查询接口返回的 `ros` 以实时计算结果为准，不依赖表内已存储值

---

## 5. 触发方式

- 命令：`project:aggregate-daily`
- 行为：每个日期先聚合 `project_daily_aggregates`，再同步生成 `project_report_hourly`
- 同日重算策略：先删除该日期旧数据，再 upsert 新数据
