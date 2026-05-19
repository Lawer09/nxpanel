# Automation Rules API

## 1. 基础信息

- 接口前缀：`/api/v3/admin/{securePath}/automation-rules`
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
| module | string | 是 | 模块标识，如 `traffic_platform` |
| keyword | string | 否 | 规则名称/描述模糊搜索 |
| enabled | int | 否 | 0/1 |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 100 |

---

## 3. 规则详情

- `GET /detail?id=1&module=traffic_platform`

---

## 4. 创建规则

- `POST /create`

Body 示例：

```json
{
  "module": "traffic_platform",
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
    { "type": "telegram_admin" }
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
  "module": "traffic_platform",
  "id": 1,
  "cooldownSeconds": 1800
}
```

---

## 6. 更新规则状态

- `POST /update-status`

Body：

```json
{
  "module": "traffic_platform",
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
  "module": "traffic_platform",
  "ruleId": 1,
  "targetIds": ["1", "2"],
  "dryRun": 0
}
```

---

## 8. 执行记录列表（Redis）

- `GET /executions`

说明：

- Redis Key：`automation:executions:{module}`
- 每模块仅保留最新 100 条（`LPUSH + LTRIM`）
- 返回顺序：最新在前

Query 参数：

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| module | string | 是 | 模块标识 |
| ruleId | int | 否 | 规则 ID |
| targetId | string | 否 | 目标 ID |
| status | string | 否 | `triggered` / `recovered` / `skipped` / `failed` |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 100 |
