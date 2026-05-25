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

---

## 邀请相关接口（V1）

> 兼容说明：以下 V1 邀请接口仍可用，但建议迁移到下方 V3 接口。

> 前缀：`/api/v1/user`
> 需要用户登录态（Authorization）

### 生成邀请码

`GET /api/v1/user/invite/save`

#### 说明

- 为当前登录用户生成一个新的邀请码。
- 当该用户未使用的邀请码数量达到系统上限（`invite_gen_limit`，默认 5）时，返回失败。

#### 返回示例

```json
{
    "data": true
}
```

#### 失败示例

```json
{
    "code": 400,
    "message": "The maximum number of creations has been reached"
}
```

### 邀请统计

`GET /api/v1/user/invite/fetch`

#### 说明

返回当前用户的邀请码列表和邀请统计信息。

#### 返回字段

| 字段 | 类型 | 说明 |
|------|------|------|
| `codes` | `array` | 当前用户未使用的邀请码列表 |
| `stat` | `array` | 统计数组，固定顺序如下 |
| `stat[0]` | `int` | 已注册邀请用户数（`invite_user_id = 当前用户`） |
| `stat[1]` | `int` | 累计已获得佣金 |
| `stat[2]` | `number` | 待确认佣金（订单已支付但未结算） |
| `stat[3]` | `int` | 当前佣金比例（%） |
| `stat[4]` | `int` | 当前可用佣金余额 |

#### 返回示例

```json
{
    "data": {
        "codes": [
            {
                "id": 101,
                "code": "AB12CD34",
                "status": 0
            }
        ],
        "stat": [12, 5600, 800, 10, 4200]
    }
}
```

### 返佣明细

`GET /api/v1/user/invite/details`

#### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `current` | `int` | 否 | 页码，默认 `1` |
| `page_size` | `int` | 否 | 每页条数，最小按 `10` 处理 |

#### 说明

- 查询当前用户作为邀请人的返佣记录明细（仅 `get_amount > 0`）。
- 按 `created_at DESC` 排序。

#### 返回示例

```json
{
    "data": [
        {
            "id": 9001,
            "invite_user_id": 1,
            "get_amount": 500,
            "created_at": 1716000000
        }
    ],
    "total": 1
}
```

---

## 邀请相关接口（V3）

> 前缀：`/api/v3/user`
> 需要用户登录态（Authorization）

### 生成邀请码

`POST /api/v3/user/invite-codes/create`

#### 说明

- 为当前登录用户生成一个新的邀请码。
- 当该用户未使用的邀请码数量达到系统上限（`invite_gen_limit`，默认 5）时，返回失败。

#### 返回示例

```json
{
    "data": {
        "created": true,
        "code": "AB12CD34"
    }
}
```

### 邀请统计

`GET /api/v3/user/invite/summary`

#### 说明

返回当前用户的邀请码列表和汇总统计（对象结构）。

#### 返回字段

| 字段 | 类型 | 说明 |
|------|------|------|
| `codes` | `array` | 当前用户未使用的邀请码列表 |
| `summary.invitedUsers` | `int` | 已注册邀请用户数 |
| `summary.totalCommission` | `int` | 累计已获得佣金 |
| `summary.pendingCommission` | `int` | 待确认佣金（订单已支付但未结算） |
| `summary.commissionRate` | `int` | 当前佣金比例（%） |
| `summary.availableCommission` | `int` | 当前可用佣金余额 |

#### 返回示例

```json
{
    "data": {
        "codes": [
            {
                "id": 101,
                "code": "AB12CD34",
                "status": 0
            }
        ],
        "summary": {
            "invitedUsers": 12,
            "totalCommission": 5600,
            "pendingCommission": 800,
            "commissionRate": 10,
            "availableCommission": 4200
        }
    }
}
```

### 返佣明细分页

`GET /api/v3/user/invite/commissions`

#### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `page` | `int` | 否 | 页码，默认 `1` |
| `pageSize` | `int` | 否 | 每页条数，默认 `10`，最大 `200` |

#### 说明

- 查询当前用户作为邀请人的返佣记录明细（仅 `get_amount > 0`）。
- 按 `created_at DESC` 排序。
- 返回统一分页结构：`data`、`total`、`page`、`pageSize`。

#### 返回示例

```json
{
    "data": {
        "data": [
            {
                "id": 9001,
                "invite_user_id": 1,
                "get_amount": 500,
                "created_at": 1716000000
            }
        ],
        "total": 1,
        "page": 1,
        "pageSize": 10
    }
}
```
