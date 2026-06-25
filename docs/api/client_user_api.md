# 客户端用户接口文档

## 查询用户订阅信息

### 基本说明

- 路由前缀：`/api/v3/user`
- 鉴权方式：用户登录鉴权，支持 `Authorization` 请求头，也兼容 `auth_data` / `authorization` 请求参数
- 请求方法：`GET`
- 接口路径：`/api/v3/user/getSubscribe`
- 接口用途：查询当前登录用户的订阅、套餐、流量、订阅链接和流量重置信息。

### 请求头

| Header | 必填 | 说明 |
| --- | --- | --- |
| Authorization | 否 | 用户登录后返回的认证信息，格式为 `Bearer xxxxxx` |

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| auth_data | string | 否 | 用户登录后返回的认证信息，支持 `Bearer xxxxxx` 或裸 token |
| authorization | string | 否 | 兼容参数，含义同 `auth_data` |

`Authorization`、`auth_data`、`authorization` 三者传一个即可。

该认证兼容方式适用于以下 V3 用户接口：

| 接口 | 方法 | 说明 |
| --- | --- | --- |
| `/api/v3/user/getSubscribe` | GET | 查询当前用户订阅信息 |
| `/api/v3/user/invite-codes/create` | POST | 创建或返回当前用户的邀请码 |
| `/api/v3/user/invite-codes/use` | POST | 使用邀请码绑定邀请关系 |
| `/api/v3/user/invite/summary` | GET | 查询当前用户邀请统计 |

#### `/api/v3/user/invite/summary` 返回补充

该接口除返回邀请总人数 `invitedUsers` 外，还返回 `users` 数组，用于展示被邀请用户标识和使用邀请码时间。

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "invitedUsers": 1,
    "users": [
      {
        "userId": 1002,
        "userIdentifier": "invitee@example.com",
        "usedAt": 1780470000
      }
    ]
  }
}
```

### 请求示例

```http
GET /api/v3/user/getSubscribe
Authorization: Bearer xxxxxx
```

```http
GET /api/v3/user/getSubscribe?auth_data=Bearer%20xxxxxx
```

```http
GET /api/v3/user/getSubscribe?auth_data=xxxxxx
```

### 成功返回示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "plan_id": 2,
    "token": "user-subscribe-token",
    "expired_at": 1780470000,
    "u": 102400,
    "d": 204800,
    "transfer_enable": 10737418240,
    "email": "user@example.com",
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "device_limit": 5,
    "speed_limit": 100,
    "next_reset_at": 1780556400,
    "plan": {
      "id": 2,
      "group_id": 1,
      "name": "Basic/Mon",
      "transfer_enable": 10,
      "show": true,
      "renew": true,
      "sell": true
    },
    "subscribe_url": "https://example.com/s/user-subscribe-token",
    "reset_day": 1
  }
}
```

### 返回字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| plan_id | integer/null | 当前用户套餐 ID；为空表示当前没有套餐 |
| token | string | 用户订阅 token |
| expired_at | integer/null | 订阅到期时间戳；`null` 表示长期有效 |
| u | integer | 上行已用流量，单位为字节 |
| d | integer | 下行已用流量，单位为字节 |
| transfer_enable | integer | 总可用流量，单位为字节 |
| email | string | 当前用户邮箱 |
| uuid | string | 用户 UUID |
| device_limit | integer/null | 设备数量限制 |
| speed_limit | integer/null | 限速，单位按系统套餐配置为准 |
| next_reset_at | integer/null | 下次流量重置时间戳 |
| plan | object/null | 当前套餐详情；没有套餐时为 `null` |
| subscribe_url | string | 用户订阅链接 |
| reset_day | integer/null | 距离下次流量重置的剩余天数 |

### 业务说明

- 已用流量 = `u + d`。
- 剩余流量 = `transfer_enable - u - d`，前端展示时应避免出现负数。
- `expired_at = null` 表示长期有效。
- `plan_id` 存在但套餐记录不存在时，接口会返回套餐不存在错误。
- 用户支付成功并完成开通后，重新调用该接口即可获取最新套餐和到期时间。
- 返回示例中的 `plan` 只展示核心字段，实际返回以套餐模型序列化结果为准。

### 失败返回示例

用户不存在：

```json
{
  "code": 400,
  "msg": "The user does not exist"
}
```

套餐不存在：

```json
{
  "code": 400,
  "msg": "Subscription plan does not exist"
}
```

---

## 客户端订阅 JSON

### 基本说明

- 路由前缀：`/api/v3/client`
- 鉴权方式：`token` 查询参数
- 请求方法：`GET`
- 接口路径：`/api/v3/client/sub/json`
- 接口用途：返回按国家缩写分组的客户端订阅节点 JSON。
- 封禁说明：该接口不因用户 `banned/is_ban` 状态拒绝返回；但仍要求 `token` 有效，并继续校验用户套餐流量与到期状态。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| token | string | 是 | 用户订阅 token |
| types | string | 否 | 节点类型过滤，多个值可用逗号分隔 |
| filter | string | 否 | 节点名称或标签关键字过滤 |
| flag | string | 否 | 客户端标识 |

### 成功返回说明

- 顶层对象 key 仍为国家缩写，例如 `US`、`JP`。
- 每个节点新增以下字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| country_code | string | 节点所属国家缩写 |
| country_name | string | 国家缩写对应的英文全称 |

### 成功返回示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "US": [
      {
        "id": 1,
        "name": "US-LosAngeles-01",
        "type": "vmess",
        "country_code": "US",
        "country_name": "United States of America",
        "host": "example.com",
        "port": 443,
        "uri": "vmess://...",
        "fast": true
      }
    ]
  }
}
```
