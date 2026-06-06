# 投放消耗同步接口与任务说明

## 1. 概述

- 管理端前缀：`/api/v3/admin/{securePath}/ad-spend-platform`
- 鉴权中间件：`admin`、`log`
- 本文说明手动同步接口、同步任务查询接口，以及定时命令与 Octane 调度下的实际执行链路。

自 `2026-06-06` 起，手动同步接口与定时命令统一复用 `App\Services\AdSpendSyncService`，共享以下步骤：

1. 创建 `ad_spend_platform_sync_jobs` 任务记录
2. 调用 `AdSpendPlatformService::fetchDailyRecords()` 拉取平台日报
3. 加载 `project_projects.project_code` 映射
4. 使用 `groupName/groupId -> project_code` 规则匹配项目
5. 写入或更新 `ad_spend_platform_daily_reports`
6. 回写任务统计字段与账号 `last_sync_at`

这样可以保证同一账号、同一日期范围、同一批返回记录，在手动触发和定时触发下得到一致的落库结果。

## 2. 手动同步

### 2.1 接口

- 方法：`POST /sync`
- 完整路径：`POST /api/v3/admin/{securePath}/ad-spend-platform/sync`

### 2.2 请求参数

```json
{
  "accountId": 1,
  "startDate": "2026-06-06",
  "endDate": "2026-06-06"
}
```

参数说明：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| accountId | int | 是 | 投放平台账号 ID |
| startDate | string | 是 | 开始日期，格式 `YYYY-MM-DD` |
| endDate | string | 是 | 结束日期，格式 `YYYY-MM-DD`，且不得早于 `startDate` |

### 2.3 处理规则

- 仅允许同步已启用账号，未找到账号返回 `404`，账号已禁用返回 `422`
- 接口内部不再重复实现写库逻辑，只做参数校验和账号可用性校验
- 实际同步由 `AdSpendSyncService::syncAccount(..., source=manual)` 执行

### 2.4 成功响应

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "jobId": 123
  }
}
```

## 3. 同步任务查询

### 3.1 任务列表

- 方法：`GET /sync-jobs`
- 完整路径：`GET /api/v3/admin/{securePath}/ad-spend-platform/sync-jobs`

查询参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| accountId / account_id | int | 否 | 账号 ID |
| platformCode / platform_code | string | 否 | 平台编码 |
| status | string | 否 | `running` / `success` / `failed` |
| startDate / start_date | string | 否 | 任务开始日期筛选 |
| endDate / end_date | string | 否 | 任务结束日期筛选 |
| page | int | 否 | 页码，默认 `1` |
| pageSize / page_size | int | 否 | 每页条数，默认 `20`，最大 `200` |

返回字段重点：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 同步任务 ID |
| platformAccountId | int | 平台账号 ID |
| platformCode | string | 平台编码 |
| accountName | string | 平台账号名称 |
| startDate | string | 同步开始日期 |
| endDate | string | 同步结束日期 |
| status | string | 任务状态 |
| totalRecords | int | 拉取到并参与处理的总记录数 |
| matchedRecords | int | 成功匹配项目并落库的记录数 |
| unmatchedRecords | int | 无法匹配项目或缺少关键字段的记录数 |
| errorMessage | string/null | 失败原因 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

### 3.2 任务详情

- 方法：`GET /sync-jobs/{id}`
- 完整路径：`GET /api/v3/admin/{securePath}/ad-spend-platform/sync-jobs/{id}`

补充字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| requestParams | object | 实际拉取参数快照 |

## 4. 写库规则

### 4.1 项目匹配

- 使用 `project_projects.project_code` 作为候选项目编码集合
- 优先取 `groupName`，若为空则回退 `group_name`、`groupId`、`group_id`
- 当分组名称中存在完整项目编码片段时，判定为匹配成功
- 未匹配到项目编码时，不写入日报表，但会计入 `unmatched_records`

### 4.2 日报写入

- 写入表：`ad_spend_platform_daily_reports`
- 唯一更新维度：
  - `platform_account_id`
  - `project_code`
  - `report_date`
  - `country`
- 使用 `updateOrCreate()`，因此重复同步同一维度数据时会更新而不是新增重复行

会被更新的核心字段：

- `impressions`
- `clicks`
- `spend`
- `ctr`
- `cpm`
- `cpc`
- `raw_group_name`

### 4.3 任务状态

同步任务表：`ad_spend_platform_sync_jobs`

状态含义：

- `running`：已创建任务，正在同步
- `success`：同步链路执行完成，统计字段已回写
- `failed`：同步执行异常，`error_message` 记录失败信息

任务完成后还会更新账号表 `ad_spend_platform_accounts.last_sync_at`。

## 5. 定时命令与调度

### 5.1 命令入口

- 命令：`php artisan ad-spend:sync --lookback-days=2`
- 调度定义：`app/Console/Kernel.php`
- 当前配置：按小时执行，保留 `onOneServer()` 与 `withoutOverlapping(55)`

日期参数规则：

- 若显式传入 `--start-date` / `--end-date`，按指定范围同步
- 若未传入，则根据 `--lookback-days` 计算日期范围
- `--account-id=*` 可限制同步的账号集合

### 5.2 Octane 常驻调度防护

线上调度仍通过 `Octane::tick(... Artisan::call('schedule:run'))` 触发。

为降低常驻 Worker 上下文导致“任务记录已生成但日报未更新”的风险，`OctaneServiceProvider` 在调用 `schedule:run` 前会先执行数据库运行态重置：

1. 回滚残留事务直到事务层级为 `0`
2. `DB::purge()` 清理默认连接
3. `DB::reconnect()` 重新建立默认连接

该改动不改变原有调度频率，也不改变对外接口和命令用法。

## 6. 可观测性

统一同步 Service 会输出以下日志：

- 开始日志：账号、平台、日期范围、触发来源（`manual` / `scheduled`）
- 结束日志：`total_records`、`matched_records`、`unmatched_records`
- 失败日志：账号、日期范围、异常信息

日志仅用于排查链路执行阶段，不新增对外 API 返回字段。
