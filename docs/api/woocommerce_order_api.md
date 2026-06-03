# WooCommerce 订单接口文档

## 基本说明

- 路由前缀：`/api/v3/application`
- 鉴权方式：复用现有应用鉴权中间件，通过 `X-App-Id` 和 `X-App-Token` 访问
- 接口路径：`POST /api/v3/application/woocommerce/order/paid`
- 接口用途：接收 WooCommerce 在订单进入 `processing` 或 `completed` 时推送的订单事件
- 幂等规则：以 `provider + order.order_id` 作为唯一键，同一订单重复推送不会重复创建或重复开通本地订单

## 触发状态说明

| trigger | 业务含义 | 本地处理 |
| --- | --- | --- |
| processing | 用户支付中 | 记录第三方回执，创建本地待支付订单，回执状态保持 `pending` |
| completed | 用户已支付 | 复用或创建本地订单，调用本地支付开通流程，回执状态更新为 `processed` |

如果同一 WooCommerce 订单先推送 `processing`，再推送 `completed`，系统会通过同一个 `order.order_id` 找到已有回执和本地待支付订单，并及时把该订单推进到已支付/已开通状态。

## 商品映射配置

接口会读取后台设置项 `woocommerce_product_mappings`，将 WooCommerce 商品映射到本地套餐和周期。

示例：

```json
{
  "68": {
    "plan_id": 2,
    "period": "quarterly"
  }
}
```

其中：

- `68`：WooCommerce 商品 ID
- `plan_id`：本地套餐 `v2_plan.id`
- `period`：本地订单周期，使用系统现有周期键，例如 `weekly`、`monthly`、`quarterly`、`half_yearly`、`yearly`、`two_yearly`、`three_yearly`、`onetime`、`reset_traffic`

## 用户匹配规则

接口使用 `tracking.device_id` 拼接本地邮箱：

```text
{tracking.device_id}@apple.com
```

例如：

```text
550E8400-E29B-41D4-A716-446655440000@apple.com
```

然后用该邮箱匹配本地 `v2_user.email`。

## 请求示例

```json
{
  "event": "woocommerce_order_paid",
  "time": "2026-06-02 15:30:00",
  "site": {
    "name": "RocketSpaceVPN",
    "url": "https://panel.rocketspacevpn.com"
  },
  "order": {
    "order_id": 1234,
    "order_number": "1234",
    "status": "processing",
    "currency": "USD",
    "total": "9.99",
    "payment_method": "stripe",
    "payment_method_title": "Stripe",
    "transaction_id": "pi_xxx",
    "customer_id": 88,
    "billing_email": "6822590328@rocketspacevpn.com",
    "date_paid": "2026-06-02 15:29:49"
  },
  "tracking": {
    "custom_tg_id": "6822590328",
    "device_id": "550E8400-E29B-41D4-A716-446655440000",
    "_vpn_sync_done": "yes"
  },
  "items": [
    {
      "product_id": 68,
      "name": "3 Month Plan",
      "quantity": 1,
      "total": "9.99"
    }
  ],
  "trigger": "processing"
}
```

## 请求字段说明

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| event | string | 是 | 固定为 `woocommerce_order_paid` |
| trigger | string | 是 | 触发时机，支持 `processing` / `completed` |
| order.order_id | integer | 是 | WooCommerce 订单 ID，用于幂等去重 |
| order.status | string | 是 | WooCommerce 订单状态，支持 `processing` / `completed` |
| order.total | numeric | 是 | 支付金额，系统会转为分后写入本地订单 |
| tracking.device_id | string | 是 | 用于匹配本地用户邮箱 |
| items | array | 是 | 至少一条商品记录 |
| items.*.product_id | integer | 是 | WooCommerce 商品 ID，用于映射本地套餐 |

## 返回示例

支付中已记录：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "received": true,
    "processed": false,
    "duplicate": false,
    "externalOrderId": "1234",
    "localOrderId": 10001,
    "status": "pending",
    "reason": null
  }
}
```

已支付并处理成功：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "received": true,
    "processed": true,
    "duplicate": false,
    "externalOrderId": "1234",
    "localOrderId": 10001,
    "status": "processed",
    "reason": null
  }
}
```

已处理订单重复推送：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "received": true,
    "processed": true,
    "duplicate": true,
    "externalOrderId": "1234",
    "localOrderId": 10001,
    "status": "processed",
    "reason": null
  }
}
```

已记录但处理失败：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "received": true,
    "processed": false,
    "duplicate": false,
    "externalOrderId": "1234",
    "localOrderId": null,
    "status": "failed",
    "reason": "user_not_found"
  }
}
```

## 常见失败原因

| reason | 说明 |
| --- | --- |
| user_not_found | 无法通过 `{tracking.device_id}@apple.com` 匹配到本地用户 |
| product_mapping_not_found | 当前 `items[0].product_id` 没有配置商品映射 |
| plan_not_found | 商品映射指向的本地套餐不存在 |
| local_order_create_failed | 本地订单创建失败 |
| local_order_not_found | 回执关联的本地订单不存在 |
| local_order_paid_failed | 本地订单支付/开通流程执行失败 |

## 补充说明

- 原始第三方请求体会保存到 `external_order_receipts.payload`，便于后台排查。
- 当返回 `status=failed` 时，表示系统已经收到第三方回执，但没有成功转换或更新本地订单。
- 参数校验失败时，接口会按系统现有格式返回 HTTP 422。
