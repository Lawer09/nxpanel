# User API 文档

## 用户列表查询

`POST /api/v3/admin/user/fetch`

支持 GET/POST。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | `string` | 否 | 按 ID 查询，逗号分隔多个，例如 `"1,2,3"` |
| `current` | `int` | 否 | 页码，默认 `1` |
| `pageSize` | `int` | 否 | 每页条数，默认 `10` |
| `meta` | `object` | 否 | 按 `register_metadata` JSON 字段筛选，key 为元数据字段名 |
| `filter` | `array` | 否 | 通用字段筛选，格式 `[{id, value}]` |
| `sort` | `array` | 否 | 排序，格式 `[{id, desc: bool}]` |

### 示例

```json
POST /api/v3/admin/user/fetch
{
    "meta": {
        "app_id": "com.example.app",
        "channel": "telegram"
    },
    "current": 1,
    "pageSize": 20,
    "filter": [
        {"id": "email", "value": "test"}
    ],
    "sort": [
        {"id": "created_at", "desc": true}
    ]
}
```

按 ID 查询（不走分页）：

```json
POST /api/v3/admin/user/fetch
{
    "id": "1,2,3"
}
```

### 返回

```json
{
    "data": [
        {
            "id": 1,
            "email": "user@example.com",
            "register_metadata": {
                "app_id": "com.example.app",
                "channel": "telegram"
            },
            "plan_id": 1,
            "plan": {"id": 1, "name": "Pro"},
            "invite_user": null,
            "group": null,
            "balance": 0,
            "commission_balance": 0,
            "subscribe_url": "...",
            "transfer_enable": 1073741824,
            "u": 0,
            "d": 0,
            "total_used": 0,
            "expired_at": 1767225600,
            "banned": false,
            "report_traffic": 0.0
        }
    ],
    "total": 1,
    "page": 1,
    "pageSize": 20
}
```

---

## 更新用户

`POST /api/v3/admin/user/update`

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | `int` | 是 | 用户 ID |
| `email` | `string` | 否 | 邮箱 |
| `password` | `string` | 否 | 密码，最少 8 位 |
| `transfer_enable` | `numeric` | 否 | 总流量 (KB) |
| `expired_at` | `int` | 否 | 过期时间戳 |
| `banned` | `bool` | 否 | 是否封禁 |
| `plan_id` | `int` | 否 | 订阅计划 ID，变更时自动更新 `group_id` |
| `commission_rate` | `int` | 否 | 返佣比例 0-100 |
| `discount` | `int` | 否 | 折扣比例 0-100 |
| `is_admin` | `bool` | 否 | 是否管理员 |
| `is_staff` | `bool` | 否 | 是否员工 |
| `u` | `int` | 否 | 上行流量 |
| `d` | `int` | 否 | 下行流量 |
| `balance` | `numeric` | 否 | 余额 |
| `commission_type` | `int` | 否 | 返佣类型 |
| `commission_balance` | `numeric` | 否 | 佣金余额 |
| `remarks` | `string` | 否 | 备注 |
| `speed_limit` | `int` | 否 | 限速 Mbps |
| `device_limit` | `int` | 否 | 设备数量限制 |
| `register_metadata` | `object` | 否 | 注册元数据，支持 `{"app_id": "..."}` 格式 |
| `invite_user_email` | `string` | 否 | 邀请人邮箱 |

### 示例

```json
POST /api/v3/admin/user/update
{
    "id": 1,
    "email": "new@example.com",
    "plan_id": 2,
    "expired_at": 1767225600,
    "register_metadata": {
        "app_id": "com.example.app",
        "channel": "telegram"
    }
}
```

### 返回

```json
{
    "data": true
}
```

```json
// 失败
{
    "code": 400202,
    "message": "用户不存在"
}
```
