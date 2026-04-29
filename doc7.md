# 流量平台统计接口文档（前端配置）

本文用于前端对接新版流量平台统计链路（以 KKOIP 为例）。

## 1. 核心逻辑

- 数据源仅提供「日累计」流量，不提供真实小时明细。
- 同步统一按 `Asia/Shanghai`（东八区）处理，避免跨天错位。
- 入库策略（kkoip）：
  - 当天无历史：将当天累计均分到 `00:00:00 ~ 23:00:00`（24 条）
  - 当天有历史：`剩余 = 当天累计 - 当天已存`，将剩余写入 `23:59:59`
- 手动同步支持日期范围：`startDate/endDate`，不传则走默认 lookback。

---

## 2. 表关系（新版）

- `traffic_platform_platforms`（平台）
  - 1:N `traffic_platform_accounts`
- `traffic_platform_accounts`（账号）
  - 1:N `traffic_platform_usage_stat`（前端主查询事实表）
  - 1:N `traffic_platform_daily_snapshots`（同步辅助快照）
  - 1:N `traffic_platform_sync_jobs`（同步审计日志）
- `traffic_platform_usage_raw` 已废弃，不再参与链路。

---

## 3. 事实表口径（前端必须遵守）

主表：`traffic_platform_usage_stat`

- `statTime`：统计时间点（唯一键维度之一）
- `statDate`：业务日期（东八区）
- `statHour`：小时（0~23）
- `statMinute`：分钟（0~59）
- `trafficBytes` / `trafficMb`：流量值

字段约束补充：

- `externalUid` / `geo` / `region` 统一空字符串 `''`，不使用 `null`
- 该规则用于保障唯一键幂等（避免 `NULL` 在唯一索引下出现重复行）

> 前端日报、小时明细、趋势都以该表为准；`/accounts/{id}/test` 仅用于连通性与实时调试，不作为报表口径。

---

## 4. 查询接口

前缀：`/api/v3/{securePath}`

统一返回：

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {}
}
```

### 4.1 小时明细

`GET /traffic-platform/usages/hourly`

Query：`platformCode, accountId, externalUid, startTime, endTime, geo, page, pageSize`

说明：

- `startTime/endTime` 基于 `stat_time` 过滤
- `externalUid/geo/region` 空值统一为 `''`（接口返回与筛选均按该规则）

用途：查看逐小时/分钟入库结果，排查“当天为 0”或“突增突降”。

### 4.2 日汇总

`GET /traffic-platform/usages/daily`

Query：`platformCode, accountId, externalUid, startDate, endDate, geo, page, pageSize`

返回口径：

- 主口径：`trafficMb`
- 兼容字段：`trafficGb = trafficMb / 1024`

用途：运营看板日维度汇总（默认主接口）。

### 4.3 月汇总

`GET /traffic-platform/usages/monthly`

Query：`platformCode, accountId, externalUid, startMonth, endMonth, page, pageSize`

### 4.4 趋势

`GET /traffic-platform/usages/trend`

Query：`platformCode, accountId, externalUid, startDate, endDate, dimension(hour|day|month)`

返回字段：

- `time`
- `trafficMb`
- `trafficGb`（由 `trafficMb` 换算）

### 4.5 排行

`GET /traffic-platform/usages/ranking`

Query：`platformCode, startDate, endDate, rankBy(account|external_uid|geo), limit`

排序规则：按 `trafficMb` 倒序。

---

## 5. 同步相关接口（辅助）

### 5.1 手动触发同步

`POST /traffic-platform/sync`

Body：

```json
{
  "accountId": 1,
  "startDate": "2026-04-27",
  "endDate": "2026-04-29"
}
```

说明：

- `platformCode` 现为可选
- 后端会根据 `accountId` 自动补齐账号绑定的 `platformCode`
- 若传了 `platformCode`，会做一致性校验；不匹配返回 `422`

### 5.2 查看同步任务

- `GET /traffic-platform/sync-jobs`
- `GET /traffic-platform/sync-jobs/{id}`

建议：前端在展示报表前，若发现当天数据异常，先检查最近任务状态是否 `success`。

### 5.3 账号测试（仅调试）

`POST /traffic-platform/accounts/{id}/test`

说明：返回三方实时视图（如 `today_use/month_use`），不直接代表报表库已落数据。

---

## 6. 前端配置建议

1) 页面默认参数
- `daily`：默认 `startDate=近7天`，`endDate=今天`
- `hourly`：默认看最近48小时

2) 展示字段
- 主展示：`trafficMb`
- 辅助展示：`trafficBytes`（详情页）
- 若需展示 GB，前端按 `trafficMb / 1024` 实时换算

3) 维度筛选约定
- `externalUid`、`geo` 传空字符串表示“空维度”
- 后端对 `null` 与 `''` 做统一归一，前端统一按 `''` 使用

3) 刷新策略
- 同步任务触发后，建议每 10~20 秒轮询 `sync-jobs`，成功后刷新 `daily/hourly`。

4) 异常排查顺序
- 先看 `sync-jobs` 是否成功
- 再看 `hourly` 是否有当日记录
- 最后再用 `accounts/{id}/test` 对比三方实时值

---

## 7. TypeScript 类型（建议）

```ts
interface ApiEnvelope<T> {
  code: number
  msg: string
  data: T
}

interface PageData<T> {
  page: number
  pageSize: number
  total: number
  data: T[]
}

interface UsageHourlyItem {
  id: number
  platformAccountId: number
  platformCode: string
  externalUid: string
  externalUsername: string | null
  statTime: string
  statHour: number
  statMinute: number
  statDate: string
  geo: string
  region: string
  trafficBytes: number
  trafficMb: number
  trafficGb: number
  accountName?: string
}

interface UsageDailyItem {
  statDate: string
  platformAccountId: number
  platformCode: string
  externalUid: string
  externalUsername: string | null
  geo: string
  region: string
  trafficBytes: number
  trafficMb: number
  trafficGb: number
  accountName?: string
}

interface SyncJobItem {
  id: number
  platformAccountId: number
  platformCode: string
  syncType: string
  startTime: string
  endTime: string
  status: 'running' | 'success' | 'failed'
  errorMessage?: string | null
  createdAt: string
  updatedAt: string
  accountName?: string
}

interface UsageTrendItem {
  time: string
  trafficMb: number
  trafficGb: number
}

interface UsageRankingItem {
  platformAccountId?: number
  platformCode?: string
  externalUid?: string
  externalUsername?: string | null
  geo?: string
  region?: string
  trafficMb: number
  trafficGb: number
  accountName?: string
}
```
