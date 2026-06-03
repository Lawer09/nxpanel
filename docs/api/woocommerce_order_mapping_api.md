# WooCommerce Order Mapping API

## Basic

- Admin route prefix: `/api/v3/{secure_path}`
- Authentication: existing admin authentication middleware
- Purpose: maintain WooCommerce product to local plan/period mappings used by third-party order callbacks

## Fetch Mapping

- Method/path: `GET /api/v3/{secure_path}/woocommerce-order-mapping/fetch`

Response example:

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "mappings": [
      {
        "product_id": 68,
        "plan_id": 2,
        "plan_name": "3 Month Plan",
        "period": "quarterly"
      }
    ],
    "periods": [
      "weekly",
      "monthly",
      "quarterly",
      "half_yearly",
      "yearly",
      "two_yearly",
      "three_yearly",
      "onetime",
      "reset_traffic"
    ]
  }
}
```

## Save Mapping

- Method/path: `POST /api/v3/{secure_path}/woocommerce-order-mapping/save`

Request example:

```json
{
  "mappings": [
    {
      "product_id": 68,
      "plan_id": 2,
      "period": "quarterly"
    },
    {
      "product_id": 69,
      "plan_id": 3,
      "period": "yearly"
    }
  ]
}
```

Request fields:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| mappings | array | Yes | Full mapping list |
| mappings.*.product_id | integer | Yes | WooCommerce product ID |
| mappings.*.plan_id | integer | Yes | Local `v2_plan.id` |
| mappings.*.period | string | Yes | Local period key |

Notes:

- Save uses full overwrite semantics. The submitted `mappings` array becomes the entire `woocommerce_product_mappings` setting.
- Duplicate `product_id` values are rejected by validation.
- Stored data is written into the existing `v2_settings.name = woocommerce_product_mappings` entry.
