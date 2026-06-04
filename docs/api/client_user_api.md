# 客户端用户接口文档

## 查询用户订阅信息

### 基本说明

- 路由前缀：`/api/v3/user`
- 鉴权方式：用户登录鉴权，需要携带 `Authorization`
- 请求方法：`GET`
- 接口路径：`/api/v3/user/getSubscribe`
- 接口用途：查询当前登录用户的订阅、套餐、流量、订阅链接和流量重置信息。

### 请求头

| Header | 必填 | 说明 |
| --- | --- | --- |
| Authorization | 是 | 用户登录后返回的认证信息，格式为 `Bearer {auth_data}` |

### 请求参数

无。

### 请求示例

```http
GET /api/v3/user/getSubscribe
Authorization: Bearer xxxxxx
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
