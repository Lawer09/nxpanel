# Sync Servers API 文档

同步服务器管理与触发类接口文档。

所有管理端路径前缀均为：`/api/v3/admin/{securePath}`。

---

## 1. 测试触发同步

- **方法/路径**：`POST /sync-servers/{server_id}/test-sync`
- **控制器**：`SyncServerController::testSync`
- **说明**：向节点服务发起 `POST /api/sync/trigger` 请求，使用 `Authorization` 请求头传递密钥。

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
- **说明**：向节点服务发起 `POST /api/sync/revenue`，日期参数为可选 query 参数。

### 2.1 请求参数

| 参数 | 位置 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- | --- |
| server_id | path | string | 是 | 同步服务器唯一 ID |
| start_date | query | string | 否 | 开始日期，格式 `Y-m-d` |
| end_date | query | string | 否 | 结束日期，格式 `Y-m-d`，且 `>= start_date` |

### 2.2 参数规则

- `start_date` 和 `end_date` 必须同时传，不能只传一个。
- 两个参数都不传时，转发为全量/默认区间同步（由节点服务自身逻辑决定）。

### 2.3 返回结构

```json
{
  "url": "http://127.0.0.1:8080/api/sync/revenue?start_date=2026-05-01&end_date=2026-05-15",
  "httpStatus": 200,
  "body": {
    "message": "ok"
  }
}
```

### 2.5 错误码

| HTTP 状态码 | 说明 |
| --- | --- |
| 404 | 服务器不存在 |
| 422 | host_ip/secret_key 未配置，或日期格式错误，或仅传了一个日期参数 |
| 504 | 节点连接超时 |
