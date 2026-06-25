# Sync Servers API 文档

同步服务器管理与触发类接口文档。

所有管理端路径前缀均为：`/api/v3/admin/{securePath}`。

---

## 1. 测试触发同步

- **方法/路径**：`POST /sync-servers/{server_id}/test-sync`
- **控制器**：`SyncServerController::testSync`
- **说明**：向节点服务发起 `POST /api/sync/trigger` 请求，使用 `Authorization` 请求头传递密钥。该测试接口保持原有行为不变。

### 1.1 请求参数

| 参数 | 位置 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- | --- |
| server_id | path | string | 是 | 同步服务器唯一 ID |

### 1.2 返回结构

```json
{
  "url": "http://127.0.0.1:8080/api/sync/trigger",
  "httpStatus": 200,
  "body": {
    "elapsed": "1.08s",
    "message": "sync completed"
  }
}
```

---

## 2. 按日期范围同步收入（可选）

- **方法/路径**：`POST /sync-servers/{server_id}/sync-revenue`
- **控制器**：`SyncServerController::syncRevenueByDate`
- **说明**：向节点服务发起 `POST /api/sync/revenue?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD&key=<API_KEY>`，日期参数为必填 query 参数，密钥通过 `key` query 参数传递。

### 2.1 请求参数

| 参数 | 位置 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- | --- |
| server_id | path | string | 是 | 同步服务器唯一 ID |
| start_date | query | string | 是 | 开始日期，格式 `Y-m-d` |
| end_date | query | string | 是 | 结束日期，格式 `Y-m-d`，且 `>= start_date` |

### 2.2 参数规则

- `start_date` 和 `end_date` 必须同时传，不能省略。
- 管理端请求远程节点时统一附加 `key=<API_KEY>`，接口响应中的 `url` 会脱敏为 `key=***`。

### 2.3 返回结构

以下为统一响应外层 `data` 中的内容；外层 `msg` 会透传远程返回的 `msg`，远程 `code != 0` 时本地接口按失败返回。

```json
{
  "url": "http://127.0.0.1:8080/api/sync/revenue?start_date=2026-05-01&end_date=2026-05-15&key=***",
  "httpStatus": 200,
  "code": 0,
  "msg": "revenue sync completed",
  "data": {
    "job": "revenue_daily",
    "start_date": "2026-05-01",
    "end_date": "2026-05-15",
    "elapsed": "8.91s"
  }
}
```

### 2.5 错误码

| HTTP 状态码 | 说明 |
| --- | --- |
| 404 | 服务器不存在 |
| 422 | host_ip/secret_key 未配置，或日期缺失/格式错误 |
| 504 | 节点连接超时 |

---

## 3. 同步广告账号元信息

- **方法/路径**：`POST /sync-servers/{server_id}/sync-account-meta`
- **控制器**：`SyncServerController::syncAccountMeta`
- **说明**：向同步节点发起 `POST /api/sync/account-meta?key=<API_KEY>`，用于触发远程广告账号元信息同步。

### 3.1 请求参数

| 参数 | 位置 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- | --- |
| server_id | path | string | 是 | 同步服务器唯一 ID |

请求体无必填参数。远程调用使用 `sync_server.secret_key` 作为 `key` query 参数，接口响应中的 `url` 会脱敏为 `key=***`。

### 3.2 返回结构

以下为统一响应外层 `data` 中的内容；外层 `msg` 会透传远程返回的 `msg`，远程 `code != 0` 时本地接口按失败返回。

```json
{
  "url": "http://127.0.0.1:8080/api/sync/account-meta?key=***",
  "httpStatus": 200,
  "code": 0,
  "msg": "account metadata sync completed",
  "data": {
    "job": "account_meta",
    "elapsed": "1.23s"
  }
}
```

---

## 4. 同步广告应用信息

- **方法/路径**：`POST /sync-servers/{server_id}/sync-apps`
- **控制器**：`SyncServerController::syncApps`
- **说明**：向同步节点发起 `POST /api/sync/apps?key=<API_KEY>`，用于触发远程广告应用信息同步。

### 4.1 请求参数

| 参数 | 位置 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- | --- |
| server_id | path | string | 是 | 同步服务器唯一 ID |

请求体无必填参数。远程调用使用 `sync_server.secret_key` 作为 `key` query 参数，接口响应中的 `url` 会脱敏为 `key=***`。

### 4.2 返回结构

以下为统一响应外层 `data` 中的内容；外层 `msg` 会透传远程返回的 `msg`，远程 `code != 0` 时本地接口按失败返回。

```json
{
  "url": "http://127.0.0.1:8080/api/sync/apps?key=***",
  "httpStatus": 200,
  "code": 0,
  "msg": "apps sync completed",
  "data": {
    "job": "apps",
    "elapsed": "2.45s"
  }
}
```

### 4.3 错误码

| HTTP 状态码 | 说明 |
| --- | --- |
| 404 | 同步服务器不存在 |
| 422 | `host_ip` 或 `secret_key` 未配置 |
| 504 | 同步节点连接超时 |

---

## 5. 同步当前收益聚合表

- **方法/路径**：`POST /sync-servers/{server_id}/sync-revenue-now-backfill`
- **控制器**：`SyncServerController::syncRevenueNowBackfill`
- **说明**：向同步节点发起 `POST /api/sync/revenue-now/backfill?key=<API_KEY>`，用于触发远程当前收益聚合表回填。

### 5.1 请求参数

| 参数 | 位置 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- | --- |
| server_id | path | string | 是 | 同步服务器唯一 ID |

请求体无必填参数。远程调用使用 `sync_server.secret_key` 作为 `key` query 参数，接口响应中的 `url` 会脱敏为 `key=***`。

### 5.2 返回结构

以下为统一响应外层 `data` 中的内容；外层 `msg` 会透传远程返回的 `msg`，远程 `code != 0` 时本地接口按失败返回。

```json
{
  "url": "http://127.0.0.1:8080/api/sync/revenue-now/backfill?key=***",
  "httpStatus": 200,
  "code": 0,
  "msg": "revenue now backfill completed",
  "data": {
    "job": "revenue_daily_backfill",
    "elapsed": "12.34s"
  }
}
```

### 5.3 远程业务失败示例

远程返回 `code=429` 或 `code=500` 时，本地接口外层 `code/msg` 会透传该远程业务码和消息，外层 `data` 中保留脱敏 URL、HTTP 状态和远程上下文。

```json
{
  "url": "http://127.0.0.1:8080/api/sync/revenue-now/backfill?key=***",
  "httpStatus": 200,
  "code": 429,
  "msg": "revenue now backfill is already running",
  "data": {
    "job": "revenue_daily_backfill",
    "elapsed": "0s"
  }
}
```

### 5.4 错误码

| HTTP 状态码/业务码 | 说明 |
| --- | --- |
| 401 | 远程节点未鉴权 |
| 404 | 同步服务器不存在 |
| 422 | `host_ip` 或 `secret_key` 未配置 |
| 429 | 远程节点任务运行中或触发频率限制 |
| 500 | 远程节点同步失败或本地转发异常 |
| 504 | 同步节点连接超时 |
