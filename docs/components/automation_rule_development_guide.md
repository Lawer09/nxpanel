# 自动化规则开发说明（通用模块版）

## 1. 总体目标

自动化规则体系采用“通用入口 + 模块处理器”模式：

- Controller 与 RuleService 不绑定具体业务模块。
- 通过 `module` 参数路由到对应模块处理器。
- 执行链路统一经 Horizon 队列。
- 执行记录统一写入 Redis（每模块仅保留最新 100 条）。

## 2. 核心结构

### 2.1 通用层

- `app/Http/Controllers/V3/Admin/AutomationRuleController.php`
- `app/Services/Automation/AutomationRuleService.php`
- `app/Services/Automation/AutomationRunnerService.php`
- `app/Services/Automation/AutomationModuleRegistry.php`
- `app/Services/Automation/Contracts/AutomationModuleHandler.php`
- `app/Services/Automation/AutomationExecutionLogService.php`

### 2.2 模块层（已落地示例）

- `app/Services/Automation/TrafficPlatformAutomationService.php`
- 模块标识：`traffic_platform`
- `app/Services/Automation/ProjectAggregateAutomationService.php`
- 模块标识：`project_aggregate`
- `app/Services/Automation/AutomationActionDispatcher.php`
- 通用动作分发：`telegram_admin` / `email` / `webhook`
- 支持判定：`supports($type)`，模块通过该方法识别是否走通用动作分发

### 2.3 执行入口

- 命令：`automation:run {module}`
- 队列任务：`RunAutomationJob`
- 调度：`Kernel` 每 5 分钟触发 `automation:run traffic_platform`

## 3. API 约定

统一前缀：`/api/v3/admin/{securePath}/automation-rules`

- `GET /models`：按 `module` 查询可用策略 model 标识
- `GET /`：规则列表
- `GET /detail`：规则详情
- `POST /create`：创建规则
- `POST /update`：更新规则
- `POST /update-status`：启停规则
- `POST /run`：手动执行
- `GET /executions`：执行记录（Redis 最新 100）

### 3.1 接口文档编写要求（按 module 区分结构）

自动化规则属于“通用入口 + 模块专有结构”模型，文档必须显式区分：

1. 通用字段（所有 module 共享）
2. `module=xxx` 场景下的专有字段、可选值、示例

以 `POST /create` 为例，必须单独标注：

- 哪些字段是 `traffic_platform` 独有（如 `targetScope.accountIds/platformCodes/includeDisabled`）
- `conditions[].metric` 的模块可用指标集合
- `actions[].type` 的模块可用动作集合

避免只给单一 JSON 示例而不说明“模块边界”，导致前端误以为字段对所有 module 通用。

## 4. 模块注册与容器约束

`AutomationModuleRegistry` 设计要求：

- 必须可被容器无参解析（避免 `iterable $handlers` 直接注入报错）。
- 支持在构造函数中一次性注入 handlers（推荐在 Provider 的 `register()` 中完成）。
- 同时保留 `registerHandlers()`/`registerHandler()` 便于后续扩展。
- 支持模块名标准化：`traffic-platform` 与 `traffic_platform` 统一为 `traffic_platform`。

`AutomationServiceProvider` 中完成模块处理器注册。

## 5. 新增模块接入步骤

以 `node_status` 为例：

1. 新建模块处理器并实现 `AutomationModuleHandler`。
2. `moduleKey()` 返回 `node_status`。
3. `defaultTargetType()` 返回默认目标类型（如 `node`）。
4. `supportedModels()` 返回前端可选 model 标识列表。
5. `run(array $params)` 完成目标筛选、指标采集、条件评估、动作分发。
6. 在 `AutomationServiceProvider` 注册该处理器。

project_aggregate 模块实现约束：

- 数据来源：`project_daily_aggregates`
- 评估粒度：当天 `projectCode` 维度（不区分国家）
- 时间口径：应用当前时区（`now()->toDateString()`）
- `ad_ecpm` 指标：按项目聚合实时重算（`SUM(ad_revenue)/SUM(ad_impressions)*1000`，保留 6 位小数）
- `ad_match_rate` 指标：按项目聚合实时重算（`SUM(ad_matched_requests)/SUM(ad_requests)*100`，百分比值，保留 6 位小数）

动作扩展（traffic_platform / project_aggregate）：

- 新增 `webhook` 动作类型，支持将告警/恢复事件推送到外部系统（如飞书机器人）
- 常用字段：`webhookUrl`、`template`、`recoverTemplate`、`headers`、`timeoutSeconds`
- 可选签名：`signing.enabled=1` 时启用，密钥使用 `signing.secret`
- 签名头默认：`X-Timestamp`、`X-Signature`（可通过 `signing.timestampHeader` / `signing.signatureHeader` 覆盖）

实现建议：

- 模块内仅保留“目标解析 + 条件评估 + 状态流转 + 模块特有动作”
- 通知类动作统一委托 `AutomationActionDispatcher`，避免每个模块重复实现

## 6. 条件与动作扩展规范

### 6.1 条件结构

```json
{
  "metric": "offline_minutes",
  "operator": "gte",
  "value": 10
}
```

支持操作符：

- `eq`, `neq`
- `gt`, `gte`
- `lt`, `lte`
- `in`, `not_in`
- `between`

### 6.2 动作结构

```json
{
  "type": "telegram_admin",
  "template": "[Alert] {rule_name} ..."
}
```

扩展动作时：

1. 在模块处理器 `dispatchOneAction()` 增加 `type` 分支。
2. 返回统一结果字段（`type`, `ok`, `message` 等）。
3. 考虑恢复态（recovery）逻辑。

## 7. 执行记录规范（Redis）

按模块分片：

- Key：`automation:executions:{module}`
- 写入：`LPUSH + LTRIM 0 99`
- 保留：每模块最新 100 条

建议记录字段：

- `rule_id`, `rule_name`
- `target_type`, `target_id`, `target_name`
- `status`
- `metrics_snapshot`
- `matched_conditions`
- `actions_snapshot`
- `action_results`
- `error_message`
- `executed_at`

## 8. 开发检查清单

1. 是否通过 `module` 走通用入口。
2. Service/Controller 是否未绑定具体模块常量。
3. 是否补齐 FormRequest 校验。
4. 是否同步更新 `docs/` 与 `version.md`。
5. 是否确认执行记录仍为每模块 100 条上限。
