# External Order Receipt API

## Basic

- Admin route prefix: `/api/v3/{secure_path}`
- Authentication: existing admin authentication middleware
- Endpoint: `GET|POST /api/v3/{secure_path}/external-order-receipt/fetch`
- Purpose: query third-party order receipt records and their local order conversion results

## Query Parameters

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| provider | string | No | Third-party provider code, currently `woocommerce` |
| status | string | No | `pending` / `processed` / `failed` |
| externalOrderId | string | No | Third-party order ID |
| userId | integer | No | Local user ID |
| localOrderId | integer | No | Local order ID created from the receipt |
| transactionId | string | No | Third-party transaction ID |
| page | integer | No | Page number, default `1` |
| pageSize | integer | No | Page size, default `20`, max `200` |

## Response

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

## Notes

- `payload` stores the raw third-party callback body for troubleshooting.
- `status=failed` means the callback was recorded but local conversion failed, for example `user_not_found` or `product_mapping_not_found`.
- `local_order` is null when the callback has not produced a local order.
