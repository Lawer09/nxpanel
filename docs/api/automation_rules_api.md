# Automation Rules API

## 1. 基础信息

- 接口前缀：`/api/v3/admin/{securePath}/automation-rules`
- 鉴权：`admin` + `log` 中间- 统一返回
```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {}
}
```

## 2. 模块model 标识

### 2.1 查询 module 可用 model

- `GET /models`

Query 参数
| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| module | string | | 模块标识，支`traffic_platform` / `traffic-platform` / `project_aggregate` / `project-aggregate` |

示例响应
```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "module": "traffic_platform",
    "models": [
      {
        "model": "traffic_platform_account",
        "name": "Traffic Platform Account",
        "module": "traffic_platform",
        "default": true
      }
    ]
  }
}
```

## 3. 规则列表

- `GET /`

Query 参数
| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| module | string | | 模块标识，支`traffic_platform` `traffic-platform` |
| keyword | string | | 规则名称/描述模糊搜索 |
| enabled | int | | 0/1 |
| page | int | | 默认 1 |
| pageSize | int | | 默认 20，最100 |

### 3.1 通用返回（所module
返回结构
```json
{
  "page": 1,
  "pageSize": 20,
  "total": 1,
  "data": [
    {
      "id": 1,
      "module": "traffic_platform",
      "name": "规则名称",
      "description": "规则描述",
      "targetType": "traffic_platform_account",
      "targetScopeJson": {},
      "conditionLogic": "all",
      "conditionsJson": [],
      "actionsJson": [],
      "cooldownSeconds": 3600,
      "recoveryEnabled": 1,
      "enabled": 1,
      "createdAt": "2026-05-19 10:00:00",
      "updatedAt": "2026-05-19 10:00:00"
    }
  ]
}
```

返回字段
| 字段 | 类型 | 说明 |
| --- | --- | --- |
| page | int | 当前|
| pageSize | int | 每页条数 |
| total | int | 总条|
| data | array | 规则列表 |

data[] 字段说明（通用）：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 规则 ID |
| module | string | 模块标识 |
| name | string | 规则名称 |
| description | string/null | 规则描述 |
| targetType | string | 目标类型 |
| targetScopeJson | object/null | 目标范围（模块相关） |
| conditionLogic | string | 条件逻辑：`all` / `any` |
| conditionsJson | array | 条件数组（模块相关） |
| actionsJson | array | 动作数组（模块相关） |
| cooldownSeconds | int | 冷却秒数 |
| recoveryEnabled | int | 是否启用恢复通知/0 |
| enabled | int | 启用状态，1/0 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

### 3.2 `module=traffic_platform` 专有返回示例

```json
{
  "page": 1,
  "pageSize": 20,
  "total": 1,
  "data": [
    {
      "id": 1,
      "module": "traffic_platform",
      "name": "余额低于 1GB 告警",
      "description": "代理流量余额阈值告",
      "targetType": "traffic_platform_account",
      "targetScopeJson": {
        "platformCodes": ["kkoip"],
        "accountIds": [1, 2],
        "includeDisabled": 0
      },
      "conditionLogic": "all",
      "conditionsJson": [
        { "metric": "balance_mb", "operator": "lte", "value": 1024 }
      ],
      "actionsJson": [
        { "type": "telegram_admin" }
      ],
      "cooldownSeconds": 3600,
      "recoveryEnabled": 1,
      "enabled": 1,
      "createdAt": "2026-05-19 10:00:00",
      "updatedAt": "2026-05-19 10:00:00"
    }
  ]
}
```

traffic_platform 专有解读
- `targetType` 固定`traffic_platform_account`
- `targetScopeJson` 使用 `accountIds/platformCodes/includeDisabled`
- `conditionsJson[].metric` 使用 traffic_platform 可用指标（见 5.2- `actionsJson[].type` 使用 traffic_platform 可用动作（见 5.2
## 4. 规则详情

- `GET /detail?id=1&module=traffic_platform`

### 4.1 通用返回（所module
返回字段（data）：与.1 通用返回」的 `data[]` 单项字段一致
### 4.2 `module=traffic_platform` 专有返回示例

```json
{
  "id": 1,
  "module": "traffic_platform",
  "name": "余额低于 1GB 告警",
  "description": "代理流量余额阈值告",
  "targetType": "traffic_platform_account",
  "targetScopeJson": {
    "platformCodes": ["kkoip"],
    "accountIds": [1, 2],
    "includeDisabled": 0
  },
  "conditionLogic": "all",
  "conditionsJson": [
    { "metric": "balance_mb", "operator": "lte", "value": 1024 }
  ],
  "actionsJson": [
    { "type": "telegram_admin" }
  ],
  "cooldownSeconds": 3600,
  "recoveryEnabled": 1,
  "enabled": 1,
  "createdAt": "2026-05-19 10:00:00",
  "updatedAt": "2026-05-19 10:00:00"
}
```

traffic_platform 专有解读
- `targetScopeJson.accountIds/platformCodes/includeDisabled` 为该模块专有目标范围字段
- `conditionsJson` `actionsJson` 的可用项受该模块能力约束（见 5.2
## 5. 创建规则

- `POST /create`

### 5.1 通用字段（所module
| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| module | string | | 模块标识（如 `traffic_platform`|
| name | string | | 规则名称 |
| description | string | | 规则描述 |
| targetType | string | | 目标类型；不传则使用模块默认|
| targetScope | object | | 目标范围（结构由 module 决定|
| conditionLogic | string | | 条件逻辑：`all` / `any`，默`all` |
| conditions | array | | 条件列表，至1 |
| actions | array | | 动作列表，至1 |
| cooldownSeconds | int | | 冷却秒数，默0 |
| recoveryEnabled | int | | 是否启用恢复通知/0，默1 |
| enabled | int | | 是否启用规则/0，默1 |

### 5.2 `module=traffic_platform` 专有结构

以下字段和取值是 `traffic_platform` 模块特有约定
- `targetType`：建议固定为 `traffic_platform_account`（该模块默认值也是此值）
- `targetScope` 专有字段  - `accountIds: int[]` 指定账号 ID 范围
  - `platformCodes: string[]` 指定平台编码范围
  - `includeDisabled: 0|1` 是否包含已禁用账- `conditions[].metric` 可用指标（traffic_platform 专有）：
  - `balance_mb`
  - `usage_1h_mb`
  - `usage_6h_mb`
  - `avg_hourly_usage_mb`
  - `eta_hours`
  - `last_sync_minutes`
  - `enabled`
  - `account_id`
  - `account_name`
  - `platform_code`
- `actions[].type` 可用动作（traffic_platform 专有）：
  - `telegram_admin`
  - `email`
  - `webhook`
  - `disable_account`
- `actions[]` 可选扩展字段（该模块当前支持）  - `template` / `recoverTemplate`
  - `subject` / `recoverSubject`（`email` 使用  - `toAdmin` / `recipients`（`email` 使用  - `webhookUrl` / `method` / `headers` / `timeoutSeconds`（`webhook` 使用  - `signing.enabled` / `signing.secret` / `signing.timestampHeader` / `signing.signatureHeader`（`webhook` 可选签名）

Body 示例
```json
{
  "module": "traffic_platform",
  "name": "余额低于 1GB 告警",
  "description": "代理流量余额阈值告",
  "targetType": "traffic_platform_account",
  "targetScope": {
    "platformCodes": ["kkoip"],
    "accountIds": [1, 2],
    "includeDisabled": 0
  },
  "conditionLogic": "all",
  "conditions": [
    { "metric": "balance_mb", "operator": "lte", "value": 1024 }
  ],
  "actions": [
    { "type": "telegram_admin" }
  ],
  "cooldownSeconds": 3600,
  "recoveryEnabled": 1,
  "enabled": 1
}
```

说明：上面示例为 `module=traffic_platform` 场景，`targetScope.accountIds/platformCodes/includeDisabled`、`conditions.metric`、`actions.type` 的可用集合均traffic_platform 模块定义
## 6. 更新规则

- `POST /update`

### 6.1 通用说明

- `module`、`id` 必填，其余字段按需部分更新
- 字段结构与「创建规则」一
### 6.2 `module=traffic_platform` 专有说明

- 专有字段范围与.2 module=traffic_platform 专有结构」一- 若更`targetScope` / `conditions` / `actions`，建议整体传入完整结构，避免客户端局部拼装导致语义不一
Body 示例
```json
{
  "module": "traffic_platform",
  "id": 1,
  "cooldownSeconds": 1800
}
```

## 7. 更新规则状
- `POST /update-status`

Body
```json
{
  "module": "traffic_platform",
  "id": 1,
  "enabled": 1
}
```

## 8. 手动执行规则

- `POST /run`

### 8.1 通用字段

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| module | string | | 模块标识 |
| ruleId | int | | 指定单条规则执行；不传则执行该模块全部启用规|
| targetIds | string[] | | 指定目标 ID 列表 |
| dryRun | int | | 1=仅评估不执行动作=正常执行 |

### 8.2 `module=traffic_platform` 专有说明

- `targetIds` 对应 `traffic_platform_accounts.id`（账ID
Body 示例
```json
{
  "module": "traffic_platform",
  "ruleId": 1,
  "targetIds": ["1", "2"],
  "dryRun": 0
}
```

返回字段（data）补充：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| ruleCount | int | 本次参与执行的规则数|
| ruleIds | int[] | 本次实际执行的规ID 列表 |
| targetCount | int | 本次命中的目标数|
| targetIds | int[] | 本次实际命中的目ID 列表 |
| triggeredCount | int | 触发告警动作次数 |
| recoveredCount | int | 触发恢复动作次数 |
| skippedCount | int | 跳过次数（如冷却中、dryRun、未命中但记录） |
| failedCount | int | 执行动作失败次数 |
| dryRun | bool | 是否 dry-run |

## 9. 执行记录列表（Redis
- `GET /executions`

说明
- Redis Key：`automation:executions:{module}`
- 每模块仅保留最100 条（`LPUSH + LTRIM`- 返回顺序：最新在
Query 参数
| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| module | string | | 模块标识 |
| ruleId | int | | 规则 ID |
| targetId | string | | 目标 ID |
| status | string | | `triggered` / `recovered` / `skipped` / `failed` |
| page | int | | 默认 1 |
| pageSize | int | | 默认 20，最100 |

返回字段（data[]）通用
| 字段 | 类型 | 说明 |
| --- | --- | --- |
| ruleId | int | 规则 ID |
| ruleName | string | 规则名称 |
| module | string | 模块标识 |
| targetType | string | 目标类型 |
| targetId | string | 目标 ID |
| targetName | string | 目标名称 |
| status | string | 执行状|
| metricsSnapshot | object | 指标快照（模块相关） |
| matchedConditions | array | 条件匹配明细 |
| actionsSnapshot | array/object | 动作快照 |
| actionResults | array/null | 动作执行结果 |
| errorMessage | string/null | 错误信息 |
| executedAt | string | 执行时间 |

`module=traffic_platform` 时：

- `targetType` 固定`traffic_platform_account`
- `metricsSnapshot` 主要包含：`balanceMb`、`usage1hMb`、`usage6hMb`、`avgHourlyUsageMb`、`etaHours`、`lastSyncMinutes`、`enabled` 等字
`module=project_aggregate` 时：

- `targetType` 固定`project_daily_aggregate`
- `targetId` `projectCode`
- `metricsSnapshot` 主要包含：`projectCode`、`projectName`、`newUsers`、`reportNewUsers`、`fbNewUsers`、`dauUsers`、`fbDauUsers`、`adRevenue`、`adRequests`、`adMatchedRequests`、`adMatchRate`、`adSpendCost`、`trafficCost`、`profit`、`roi`、`adSpendCpi`、`adEcpm`

## 10. `module=project_aggregate` 专有说明

### 10.1 适用范围

- 按当天项目维度（不区分国家）`project_daily_aggregates` 做聚合评- 日期使用应用当前时区（`now()->toDateString()`
### 10.2 targetScope 专有字段

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| projectCodes | string[] | | 指定项目编码范围；不传表示全项目 |

说明：创更新规则时，`targetScope.projectCodes` 会按白名单校验并持久化，用于实际执行范围过滤
### 10.3 conditions[].metric 可用指标

- `new_users`
- `report_new_users`
- `fb_new_users`
- `dau_users`
- `fb_dau_users`
- `ad_revenue`
- `ad_requests`
- `ad_matched_requests`
- `ad_match_rate`
- `ad_spend_cost`
- `traffic_cost`
- `profit`
- `roi`
- `ad_spend_cpi`
- `ad_ecpm`

说明：`ad_ecpm` 按当天项目维度聚合实时重算，公式`SUM(ad_revenue)/SUM(ad_impressions)*1000`（保6 位小数）
说明：`ad_match_rate` 按当天项目维度聚合实时重算，公式`SUM(ad_matched_requests)/SUM(ad_requests)*100`（百分比值，保留 6 位小数）
### 10.4 actions[].type 可用动作

- `telegram_admin`
- `email`
- `webhook`

`webhook` 动作扩展字段
- `webhookUrl`：Webhook 地址（必填）
- `method`：请求方法（可选，支持 `POST`/`PUT`/`PATCH`，默`POST`- `template` / `recoverTemplate`：通知内容模板（可选）
- `headers`：自定义请求头对象（可选）
- `timeoutSeconds`：请求超时秒数（可选，默认 10- `signing`：签名配置（可选）
  - `enabled`：`1/0`，默`0`
  - `secret`：签名密  - `timestampHeader`：非飞书 webhook 使用的时间戳请求头名，默`X-Timestamp`
  - `signatureHeader`：非飞书 webhook 使用的签名请求头名，默认 `X-Signature`

说明
- `webhookUrl` 为飞书自定义机器人地址（如 `https://open.feishu.cn/open-apis/bot/v2/hook/...`）时  - 单条与多条告警都会按飞书 `msg_type=text` / `content.text` 结构发  - `signing.enabled=1` 且传`secret` 时，会将 `timestamp` `sign` 写入请求- `webhookUrl` 为普webhook 地址时：
  - 单条消息保持原始事件 JSON 结构
  - 多条消息返回 `message + mergedCount + events` 的合并结  - 签名信息按请求头方式追加

### 10.5 创建规则示例（project_aggregate
```json
{
  "module": "project_aggregate",
  "name": "项目当日 eCPM 过低告警",
  "description": "当天项目聚合 eCPM 低于阈值时告警",
  "targetType": "project_daily_aggregate",
  "targetScope": {
    "projectCodes": ["A003", "A005"]
  },
  "conditionLogic": "all",
  "conditions": [
    { "metric": "ad_ecpm", "operator": "lt", "value": 2.5 }
  ],
  "actions": [
    {
      "type": "webhook",
      "webhookUrl": "https://open.feishu.cn/open-apis/bot/v2/hook/xxxx",
      "template": "[Project Alert] {rule_name} | {project_name}({project_code}) | ad_ecpm={ad_ecpm}",
      "signing": {
        "enabled": 1,
        "secret": "your-feishu-secret"
      }
    }
  ],
  "cooldownSeconds": 3600,
  "recoveryEnabled": 1,
  "enabled": 1
}
```

## 10.6 Webhook 合并消息说明

- 当同一 webhook 在合并窗口内收到了多条消息时，合并后的消息内容按每条 message 直接换行拼接。
- 不再附加序号、统计头或 [Alert] / [Recovered] 标签。
- 该规则同时适用于飞书机器人 webhook 和普通 webhook 的合并消息体。

## 10.7 Webhook 模板空消息兜底

- webhook 消息会自动注入通用变量：rule_name、rule_id、target_name、target_id、target_type、module、status。
- 如果自定义 template 或 recoverTemplate 渲染后为空字符串，会自动回退到模块默认模板，避免发送空消息。


## 11. `module=project_ad_revenue_hourly` 专有说明

### 11.1 适用范围

- 评估来源表：`ad_revenue_hourly`
- 评估时间桶：上一完整小时，即 `now()->startOfHour()->subHour()`
- 项目归属不读取 `ad_revenue_hourly.project_id`
- 项目与广告账号映射统一取自 `project_ad_platform_accounts`
- 关联键为 `ad_revenue_hourly.account_id = project_ad_platform_accounts.ad_platform_account_id`
- 目标 ID 使用 `project_code`

### 11.2 targetScope 专有字段

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| projectCodes | string[] | 否 | 指定项目代号范围；不传表示全部项目 |
| includeDisabled | int | 否 | `1` 表示包含已禁用的 `project_ad_platform_accounts` 映射，默认 `0` |

### 11.3 conditions[].metric 可用指标

- `row_count`
- `ad_requests`
- `matched_requests`
- `match_rate`
- `impressions`
- `show_rate`
- `clicks`
- `ctr`
- `estimated_earnings_micros`
- `estimated_earnings`
- `ecpm_micros`
- `ecpm`
- `anomaly_count`

口径说明：
- `match_rate = matched_requests / ad_requests`
- `show_rate = impressions / matched_requests`
- `ctr = clicks / impressions`
- `ecpm_micros = estimated_earnings_micros * 1000 / impressions`
- `ecpm = estimated_earnings * 1000 / impressions`
- 分母为 `0` 时，比率字段统一返回 `0`

### 11.4 actions[].type 可用动作

- `telegram_admin`
- `email`
- `webhook`

默认模板：
- Alert：`[Project Hourly Ad Alert] {rule_name} | {project_name}({project_code}) | revenue={estimated_earnings} | imp={impressions} | ctr={ctr} | ecpm={ecpm}`
- Recover：`[Project Hourly Ad Recovery] {rule_name} | {project_name}({project_code}) | revenue={estimated_earnings}`

### 11.5 创建规则示例（project_ad_revenue_hourly）

```json
{
  "module": "project_ad_revenue_hourly",
  "name": "上一小时广告收益过低告警",
  "description": "上一完整小时广告收益低于阈值时通知",
  "targetType": "project_ad_revenue_hourly",
  "targetScope": {
    "projectCodes": ["A003", "A005"],
    "includeDisabled": 0
  },
  "conditionLogic": "all",
  "conditions": [
    { "metric": "estimated_earnings", "operator": "lt", "value": 1 }
  ],
  "actions": [
    { "type": "telegram_admin" }
  ],
  "cooldownSeconds": 3600,
  "recoveryEnabled": 1,
  "enabled": 1
}
```

### 11.6 手动运行说明

- `POST /run` 时，`targetIds` 对应 `project_code`
- 仅传 `targetIds` 时，会在模块默认目标集合上按项目代号过滤
- 无数据项目仍会被评估，因此可以直接使用 `row_count = 0`、`estimated_earnings = 0` 等统计字段编写规则
