# 第三方订单回执查询接口文档

## 基本说明

- 管理路由前缀：`/api/v3/{secure_path}`
- 鉴权方式：复用现有管理员鉴权中间件
- 接口路径：`GET|POST /api/v3/{secure_path}/external-order-receipt/fetch`
- 接口用途：查询第三方订单回执记录，以及它们转换成本地订单后的处理结果

## 查询参数

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| provider | string | 否 | 第三方来源标识，当前主要为 `woocommerce` |
| status | string | 否 | 回执状态，支持 `pending` / `processed` / `failed`。其中 `pending` 表示支付中，`processed` 表示已支付并完成本地处理 |
| externalOrderId | string | 否 | 第三方订单 ID |
| userId | integer | 否 | 本地用户 ID |
| localOrderId | integer | 否 | 由该回执转换生成的本地订单 ID |
| transactionId | string | 否 | 第三方交易流水号 |
| page | integer | 否 | 页码，默认 `1` |
| pageSize | integer | 否 | 每页条数，默认 `20`，最大 `200` |

## 返回示例

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "data": [
      {
        "id": 1,
        "provider": "woocommerce",
        "external_order_id": "1234",
        "status": "processed",
        "user_id": 10,
        "local_order_id": 10001,
        "product_id": 68,
        "plan_id": 2,
        "period": "quarterly",
        "transaction_id": "pi_xxx",
        "payload": {
          "event": "woocommerce_order_paid"
        },
        "error_message": null,
        "created_at": 1780470000,
        "updated_at": 1780470005,
        "user": {
          "id": 10,
          "email": "550E8400-E29B-41D4-A716-446655440000@apple.com",
          "telegram_id": null
        },
        "local_order": {
          "id": 10001,
          "trade_no": "202606030001",
          "status": 3,
          "total_amount": 999,
          "paid_at": 1780470005
        }
      }
    ],
    "total": 1,
    "page": 1,
    "pageSize": 20
  }
}
```

## 字段说明

- `payload`：保存第三方原始回调报文，便于后台排查问题。
- `status=pending`：表示 WooCommerce 已推送 `processing`，用户仍处于支付中，本地订单保持待支付状态。
- `status=processed`：表示 WooCommerce 已推送 `completed`，本地订单已进入支付/开通流程。
- `status=failed`：表示系统已经接收到第三方回执，但在转换或更新本地订单时失败，例如 `user_not_found`、`product_mapping_not_found`。
- `local_order`：当回执尚未成功生成本地订单时，该字段可能为 `null`。

## 典型用途

- 按第三方订单 ID 查询某一笔回执是否已接收
- 查看某条回执是否已成功转换为本地订单
- 通过 `failed` 状态筛查需要人工处理的异常订单
