# Application Route API（V3）

本文档描述通过应用身份访问的 V3 管理能力接口。

## 基本说明

- 路由前缀：`/api/v3/{securePath}`
- 鉴权中间件：`app` + `log`
- 其中 `{securePath}` 由 `admin_setting('secure_path', ...)` 动态生成

## 应用鉴权

优先使用请求头传递：

- `X-App-Id`：应用 ID
- `X-App-Token`：应用 Token

兼容请求参数：

- `appId` / `app_id`
- `appToken` / `app_token`

鉴权失败返回 403。

---

## 应用客户端（只读）

### 1) 应用列表

- 方法/路径：`GET /api/v3/{securePath}/app-client/fetch`

请求参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| page_size | int | 否 | 每页数量，默认 50，最大 200 |

### 2) 应用详情

- 方法/路径：`GET /api/v3/{securePath}/app-client/detail`

请求参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| id | int | 是 | 应用主键 ID |

---

## 兼容说明

- 管理员写接口（`save/update/drop/resetSecret`）仍仅在 Admin 路由可访问。
- 应用路由当前仅开放只读接口（`fetch`、`detail`）。
