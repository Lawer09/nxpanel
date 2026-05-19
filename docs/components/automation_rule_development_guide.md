# 自动化规则开发说明（通用模块版）

## 1. 总体目标

自动化规则体系采用“通用入口 + 模块处理器”模式：

- Controller 与 RuleService 不绑定具体业务模块
- 通过 `module` 参数路由到对应模块处理器
- 执行链路统一走 Horizon 队列
- 执行记录统一写入 Redis（每模块保留最新 100 条）

---

## 2. 当前核心结构

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

### 2.3 执行入口

- 命令：`automation:run {module}`
- 队列任务：`RunAutomationJob`
- 调度：`Kernel` 每 5 分钟触发 `automation:run traffic_platform`

---

## 3. API 约定

统一前缀：`/api/v3/admin/{securePath}/automation-rules`

- `GET /`：规则列表（必须带 `module`）
- `GET /detail`：规则详情（`module + id`）
- `POST /create`：创建规则（body 内带 `module`）
- `POST /update`：更新规则（body 内带 `module + id`）
- `POST /update-status`：规则启停（body 内带 `module + id`）
- `POST /run`：手动执行（body 内带 `module`）
- `GET /executions`：执行记录（query 内带 `module`）

---

## 4. 新增模块开发步骤

以 `node_status` 为例：

1. 新建模块处理器类，实现 `AutomationModuleHandler`  
2. 实现 `moduleKey()` 返回 `node_status`  
3. 实现 `defaultTargetType()`（如 `node`）  
4. 实现 `run(array $params)`：完成目标筛选、指标采集、条件评估、动作分发  
5. 处理器会被 `AutomationModuleRegistry` 自动发现并按 `module` 路由  
6. 前端调用通用接口时仅切换 `module=node_status`

---

## 5. 条件与动作扩展规范

### 5.1 条件结构

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

### 5.2 动作结构

```json
{
  "type": "telegram_admin",
  "template": "[Alert] {rule_name} ..."
}
```

动作扩展要求：

1. 在模块处理器 `dispatchOneAction()` 添加 `type` 分支  
2. 返回统一结果字段（`type`, `ok`, `message` 等）  
3. 同时考虑恢复态（recovery）行为  
4. 更新 API 文档中的动作说明

---

## 6. 执行记录规范（Redis）

按模块分片存储：

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

---

## 7. 调度与并发控制

1. `Kernel` 只负责触发，不跑重逻辑  
2. 命令默认只投递队列（`--sync` 仅用于调试）  
3. Job 使用模块级锁：`automation:run:{module}`  
4. Horizon 队列建议按模块拆分（业务量上来后）

---

## 8. 开发检查清单

1. 是否通过 `module` 走通用入口  
2. 是否避免 Service/Controller 绑定具体模块常量  
3. 是否补齐 FormRequest 校验  
4. 是否补齐文档与 `version.md`  
5. 是否确认执行记录仍为每模块 100 条上限

