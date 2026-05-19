# Traffic Platform 自动化规则 API

## 1. 基础信息

- 接口前缀：`/api/v3/admin/{securePath}/traffic-platform/automation-rules`
- 鉴权：`admin` + `log` 中间件
- 统一返回：

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {}
}
```

---

## 2. 规则列表

- `GET /`

Query 参数：

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| keyword | string | 否 | 按规则名称/描述模糊搜索 |
| enabled | int | 否 | 0/1 |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 100 |

---

## 3. 规则详情

- `GET /detail?id=1`

---

## 4. 创建规则

- `POST /create`

Body 示例：

```json
{
  "name": "余额低于 1GB 告警",
  "description": "代理流量余额阈值告警",
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
    { "type": "telegram_admin" },
    {
      "type": "email",
      "toAdmin": 1,
      "subject": "[TrafficPlatform] Alert - {rule_name}",
      "template": "规则 {rule_name} 命中，账号 {account_name} 剩余 {balance_mb}MB"
    }
  ],
  "cooldownSeconds": 3600,
  "recoveryEnabled": 1,
  "enabled": 1
}
```

---

## 5. 更新规则

- `POST /update`

Body 示例：

```json
{
  "id": 1,
  "cooldownSeconds": 1800,
  "actions": [
    {
      "type": "telegram_admin",
      "template": "[Alert] {rule_name} {account_name} balance={balance_mb}MB",
      "recoverTemplate": "[Recovery] {rule_name} {account_name} balance={balance_mb}MB"
    }
  ]
}
```

---

## 6. 更新规则状态

- `POST /update-status`

Body：

```json
{
  "id": 1,
  "enabled": 1
}
```

---

## 7. 手动执行规则

- `POST /run`

Body 示例：

```json
{
  "ruleId": 1,
  "accountIds": [1, 2],
  "dryRun": 0
}
```

返回字段（`data`）：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| ruleCount | int | 本次执行规则数 |
| targetCount | int | 本次评估目标数 |
| triggeredCount | int | 告警触发次数 |
| recoveredCount | int | 恢复次数 |
| skippedCount | int | 因冷却或 dryRun 跳过次数 |
| failedCount | int | 执行失败次数 |
| dryRun | bool | 是否为 dryRun |

---

## 8. 条件与动作说明

### 条件 operator

- `eq`, `neq`
- `gt`, `gte`
- `lt`, `lte`
- `in`, `not_in`
- `between`

### 首期内置 metric

- `balance_mb`
- `usage_1h_mb`
- `usage_6h_mb`
- `avg_hourly_usage_mb`
- `eta_hours`
- `last_sync_minutes`
- `enabled`

### 首期内置动作

- `telegram_admin`：向管理员 Telegram 发送通知
- `email`：向管理员和/或指定收件箱发送邮件
- `disable_account`：命中后自动禁用目标代理账号
