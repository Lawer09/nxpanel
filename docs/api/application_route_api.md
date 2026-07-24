# Application Route API（V3）

本文档描述通过应用身份访问的 V3 管理能力接口。

## 基本说明

- 路由前缀：`/api/v3/application`
- 鉴权中间件：`app` + `log`

## 应用鉴权

优先使用请求头传递：

- `X-App-Id`：应用 ID
- `X-App-Token`：应用 Token

兼容请求参数：

- `appId` / `app_id`
- `appToken` / `app_token`

鉴权失败返回 403。

---

## 报表查询

### 1) 项目报表查询

- 方法/路径：`POST /api/v3/application/report/project/query`
- 对应控制器方法：`ReportController::queryProjectReport`
- 鉴权：应用身份（`app` 中间件）

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 开始日期，默认昨天 |
| dateTo | string | 否 | 结束日期，默认今天 |
| hourFrom | int | 否 | 开始小时 0-23 |
| hourTo | int | 否 | 结束小时 0-23 |
| groupBy | string[] | 否 | 维度分组：`reportDate`/`hour`/`projectCode`/`country` |
| filters.projectCodes | string[] | 否 | 项目代号过滤 |
| filters.countries | string[] | 否 | 国家过滤 |
| filters.exclude.projectCodes | string[] | 否 | 排除项目代号过滤；与 `filters.projectCodes` 同时存在时先包含再排除 |
| filters.exclude.countries | string[] | 否 | 排除国家过滤，服务端统一转大写 |
| filters.adStatuses | string[] | 否 | 项目投放状态过滤，匹配 `project_projects.ad_status`；仅用于筛选，不在报表返回字段中输出 |
| filters.appPlatforms | string[] | 否 | 项目应用平台过滤，匹配 `project_projects.app_platform`；仅用于筛选，不在报表返回字段中输出 |
| filters.departments | string[] | 否 | 项目部门过滤，匹配 `project_projects.department`；仅用于筛选，不在报表返回字段中输出 |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 50，最大 200 |
| orderBy | string | 否 | `reportDate`/`hour`/`projectCode`/`country`/`installUsers`/`dauUsers`/`adRevenue`/`adSpendCost`/`ros`/`id`/`updatedAt` |
| orderDirection | string | 否 | `asc` / `desc` |

当 `groupBy` 包含 `projectCode` 时，项目报表返回行会附带 `appInfos` 字段，来源于 `app_infos`，并通过 `project_user_app_map` 按当前行 `projectCode` 映射 appId 后批量加载；字段结构与管理端项目列表的 `appInfos` 一致；无应用信息时返回空数组 `[]`。

---

## 1. 表名与维度

- 表名：`project_report_hourly`
- 维度：`date + hour + project_code + country`
- 国家归一规则与项目聚合保持一致：空值统一为 `XX`，并转大写

---

## 2. 字段定义

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | bigint | 主键 |
| date | date | 报表日期 |
| hour | tinyint | 小时（0-23） |
| project_code | varchar(100) | 项目代号 |
| country | varchar(50) | 国家（空值归一 `XX`） |
| install_users | int unsigned | 安装数（全生命周期首次上报小时） |
| dau_users | int unsigned | 小时活跃用户数（去重用户） |
| ad_revenue | decimal(20,6) | 按小时分配后的广告收益 |
| ad_spend_cost | decimal(20,6) | 按小时分配后的广告花费 |
| ros | decimal(20,6) nullable | 收益转化指标 |
| created_at | timestamp | 创建时间 |
| updated_at | timestamp | 更新时间 |

---

## 项目管理

### 1) 更新项目状态相关字段

- 方法/路径：`POST /api/v3/application/projects/update-status-fields`
- 对应控制器方法：`ProjectController::updateStatusFields`
- Request：`ProjectUpdateStatusFieldsRequest`
- 鉴权：应用身份（`app` 中间件）
- 说明：用于应用侧按项目 ID 或项目代号更新项目管理中的状态相关字段，仅允许修改 `project_projects` 表的状态字段，不修改项目名称、负责人、账号绑定等其他信息。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 否 | 项目 ID；`id` 与 `projectCode` 至少传一个，同时传入时优先按 `id` 定位 |
| projectCode | string | 否 | 项目代号；`id` 与 `projectCode` 至少传一个 |
| status | string | 否 | 项目系统状态，可选：`active` / `inactive` / `archived` |
| adStatus | string/null | 否 | 投放状态，最大 50 字符；传 `null` 可清空 |
| domainInfoStatus | string/null | 否 | 域名信息状态，最大 50 字符；传 `null` 可清空 |
| facebookInfoStatus | string/null | 否 | FB 信息状态，最大 50 字符；传 `null` 可清空 |
| admobAccountStatus | string/null | 否 | Admob 账号状态，最大 50 字符；传 `null` 可清空 |

约束：

- `status`、`adStatus`、`domainInfoStatus`、`facebookInfoStatus`、`admobAccountStatus` 至少传入一个。
- 接口返回只包含项目标识与状态字段，避免应用侧获取项目完整敏感元数据。

### 请求示例

```json
{
  "projectCode": "P001",
  "status": "active",
  "adStatus": "白包在线",
  "domainInfoStatus": "completed",
  "facebookInfoStatus": "completed",
  "admobAccountStatus": null
}
```

### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "id": 1,
    "projectCode": "P001",
    "status": "active",
    "adStatus": "白包在线",
    "domainInfoStatus": "completed",
    "facebookInfoStatus": "completed",
    "admobAccountStatus": null,
    "updatedFields": [
      "status",
      "adStatus",
      "domainInfoStatus",
      "facebookInfoStatus",
      "admobAccountStatus"
    ],
    "updatedAt": "2026-07-24T10:00:00.000000Z"
  }
}
```

### 错误

| HTTP 状态码 | 说明 |
| --- | --- |
| 403 | 应用鉴权失败或应用已禁用 |
| 404 | 项目不存在 |
| 422 | 参数校验失败，例如未传项目标识、未传任何状态字段、`status` 枚举不合法 |

---

## Tg Bot

### 1) 消息上报

- 方法/路径：`POST /api/v3/application/tg-bot/say`
- 鉴权：应用身份（`app` 中间件）

请求参数：

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| receiveAt | int | 是 | 接收时间戳 |
| content | string | 是 | 消息内容 |

说明：

- 当前接口仅接收参数并返回，不做业务处理。

---

## WooCommerce Order

### 1) Paid order callback

- Method/path: `POST /api/v3/application/woocommerce/order/paid`
- Authentication: application authentication (`app` middleware)
- Description: receives WooCommerce paid order events triggered by `processing` or `completed`
- Idempotency: uses `order.order_id` with provider `woocommerce`; duplicate pushes do not create or open a second local order
- Details: see `docs/api/woocommerce_order_api.md`
