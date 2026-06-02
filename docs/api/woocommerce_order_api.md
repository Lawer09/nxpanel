# WooCommerce Order API

## Basic

- Route prefix: `/api/v3/application`
- Authentication: existing application authentication middleware, using `X-App-Id` and `X-App-Token`
- Endpoint: `POST /api/v3/application/woocommerce/order/paid`
- Purpose: receive WooCommerce paid order events fired by `processing` or `completed`
- Idempotency: `provider + order.order_id` is unique, so repeated `processing` / `completed` pushes for the same order do not open the local order twice

## Product Mapping

The endpoint reads product mappings from the admin setting `woocommerce_product_mappings`.

Example:

```json
{
  "68": {
    "plan_id": 2,
    "period": "quarterly"
  }
}
```

Supported `period` values are the existing plan period keys, such as `weekly`, `monthly`, `quarterly`, `half_yearly`, `yearly`, `two_yearly`, `three_yearly`, `onetime`, and `reset_traffic`.

## User Matching

The endpoint uses `tracking.device_id` to find the local user:

```text
{tracking.device_id}@apple.com
```

For example, `550E8400-E29B-41D4-A716-446655440000` matches the local user email `550E8400-E29B-41D4-A716-446655440000@apple.com`.

## Request

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

Required fields:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| event | string | Yes | Must be `woocommerce_order_paid` |
| trigger | string | Yes | `processing` or `completed` |
| order.order_id | integer | Yes | WooCommerce order ID, used for idempotency |
| order.status | string | Yes | `processing` or `completed` |
| order.total | numeric | Yes | Paid amount, converted to cents for local order amount |
| tracking.device_id | string | Yes | Used to match `{device_id}@apple.com` |
| items | array | Yes | At least one item |
| items.*.product_id | integer | Yes | Used to resolve local plan mapping |

## Response

Processed successfully:

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

Duplicate push:

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

Recorded but not processed:

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

Common `reason` values:

| Reason | Description |
| --- | --- |
| user_not_found | No local user matched `{tracking.device_id}@apple.com` |
| product_mapping_not_found | No mapping exists for `items[0].product_id` |
| plan_not_found | The mapped local plan does not exist |
| local_order_create_failed | Local order creation failed |
| local_order_paid_failed | Existing local paid/open flow failed |

Validation failures return HTTP 422 using the existing validation response format.
