# Traffic Platform Allocation Automation

## Overview

`traffic_allocation` is a `traffic_platform` automation action. It creates a traffic allocation order when an enabled traffic account rule matches.

The action calls the traffic platform service:

```text
POST {TRAFFIC_PLATFORM_SERVICE_BASE_URL}/api/traffic-platform/traffic-allocations/orders
```

Headers:

```text
X-API-Key: {TRAFFIC_PLATFORM_SERVICE_API_KEY}
Content-Type: application/json
```

Request body:

```json
{
  "account_id": 1,
  "target_user_id": "2",
  "target_username": "kookeey",
  "amount_gb": 10
}
```

## Action Fields

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| type | string | Yes | Must be `traffic_allocation` |
| targetUserId | string | Yes | Allocation target user ID, sent as `target_user_id` |
| targetUsername | string | Yes | Allocation target username, sent as `target_username` |
| amountGb | number | Yes | Allocation amount in GB, sent as `amount_gb` |

`account_id` is always the matched `traffic_platform_accounts.id`; it is not accepted from action config.

## Runtime Behavior

- The action only runs during the alert trigger stage.
- The recovery stage skips allocation requests.
- Duplicate prevention depends on the rule `cooldownSeconds`.
- If the upstream service returns a non-2xx response or times out, the automation execution is logged as `failed`.
- The API key must be configured through environment variables and must not be stored in rule JSON.

## Configuration

Add these environment variables:

```text
TRAFFIC_PLATFORM_SERVICE_BASE_URL=http://127.0.0.1:8080
TRAFFIC_PLATFORM_SERVICE_API_KEY=replace-with-your-key
TRAFFIC_PLATFORM_SERVICE_TIMEOUT_SECONDS=15
```

## Rule Example

```json
{
  "module": "traffic_platform",
  "name": "代理账户余额低于 1GB 自动划转",
  "targetType": "traffic_platform_account",
  "targetScope": {
    "accountIds": [1],
    "includeDisabled": 0
  },
  "conditionLogic": "all",
  "conditions": [
    { "metric": "balance_mb", "operator": "lte", "value": 1024 }
  ],
  "actions": [
    {
      "type": "traffic_allocation",
      "targetUserId": "2",
      "targetUsername": "kookeey",
      "amountGb": 10
    }
  ],
  "cooldownSeconds": 3600,
  "recoveryEnabled": 0,
  "enabled": 1
}
```

## Manual Allocation API

The admin API can also create a traffic allocation order manually.

Endpoint:

```text
POST /api/v3/admin/{securePath}/traffic-platform/traffic-allocations/create
```

Request:

```json
{
  "accountId": 1,
  "targetUserId": "2",
  "targetUsername": "kookeey",
  "amountGb": 10
}
```

Fields:

| Field | Type | Required | Description |
| --- | --- | --- | --- |
| accountId | int | Yes | Local `traffic_platform_accounts.id` |
| targetUserId | string | Yes | Allocation target user ID |
| targetUsername | string | Yes | Allocation target username |
| amountGb | number | Yes | Allocation amount in GB |

Response `data`:

```json
{
  "accountId": 1,
  "accountName": "Main Traffic Account",
  "targetUserId": "2",
  "targetUsername": "kookeey",
  "amountGb": 10,
  "statusCode": 200,
  "response": {
    "code": 0,
    "data": {
      "order_id": "order-2001"
    }
  }
}
```
