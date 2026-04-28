# 项目广告归因前端对接接口说明（V3）

本文用于前端对接「项目广告账号绑定 + 项目应用归因映射」。

## 0. 基础说明

- 接口前缀：`/api/v3/{secure_path}`
- 统一返回：

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {}
}
```

- 管理端接口，需管理员登录态（沿用现有 admin 鉴权）

---

## 1. 为什么要两层绑定

当前后端有两层关系：

1) 项目 ↔ 广告账号（管理层）
2) 项目 ↔ 广告平台 App（归因层）

原因：`ad_revenue_daily` 按 `provider_app_id` 产出，单账号下可能多个 App 且属于不同项目。
所以收益归因以「项目-应用映射」为准。

---

## 2. 项目绑定广告账号接口（管理层）

### 2.1 查询项目已绑定广告账号

`GET /projects/{id}/ad-accounts`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| enabled | int | 否 | 1/0 |

### 2.2 新增绑定

`POST /projects/{id}/ad-accounts`

请求体：

```json
{
  "adPlatformAccountId": 5,
  "platformCode": "admob",
  "externalAppId": "",
  "externalAdUnitId": "",
  "bindType": "account",
  "enabled": 1,
  "remark": "整个广告账号归属项目"
}
```

### 2.3 更新绑定

`PUT /projects/{id}/ad-accounts/{relationId}`

### 2.4 删除绑定

`DELETE /projects/{id}/ad-accounts/{relationId}`

---

## 3. 项目应用映射接口（归因层，核心）

> 用于将 `sourcePlatform + accountId + providerAppId` 映射到项目。

### 3.1 查询映射列表

`GET /project-app-mappings`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| projectId | int | 否 | 项目 ID |
| sourcePlatform | string | 否 | 平台编码 |
| accountId | int | 否 | 账号 ID |
| status | string | 否 | `enabled` / `disabled` |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20 |

返回 `data` 结构：

```json
{
  "data": [
    {
      "id": 1,
      "projectId": 101,
      "sourcePlatform": "admob",
      "accountId": 5,
      "providerAppId": "ca-app-pub-xxx~123",
      "status": "enabled",
      "account": {
        "id": 5,
        "accountName": "admob-main",
        "sourcePlatform": "admob"
      },
      "createdAt": "2026-04-28 10:00:00",
      "updatedAt": "2026-04-28 10:00:00"
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 20
}
```

### 3.2 新增映射

`POST /project-app-mappings`

请求体：

```json
{
  "projectId": 101,
  "sourcePlatform": "admob",
  "accountId": 5,
  "providerAppId": "ca-app-pub-xxx~123",
  "status": "enabled"
}
```

说明：
- 唯一键：`project_id + source_platform + account_id + provider_app_id`
- 重复会返回 `422`（该项目映射已存在）

### 3.3 更新映射

`PUT /project-app-mappings/{id}`

请求体同新增。

### 3.4 启用/禁用映射

`PATCH /project-app-mappings/{id}/status`

请求体：

```json
{
  "status": "disabled"
}
```

---

## 4. 前端对接流程建议

1. 先完成「项目 ↔ 广告账号」绑定（第 2 章）
2. 再完成「项目 ↔ 应用」映射（第 3 章）
3. 报表归因按第 3 章映射生效；仅绑定账号不足以精确归因到 App

---

## 5. 日聚合说明（非前端接口）

后端已提供项目日报聚合任务（定时每 5 分钟刷新当天）：

- 命令：`php artisan project:aggregate-daily`
- 回补：`php artisan project:aggregate-daily --start-date=2026-04-01 --end-date=2026-04-28`

该任务会更新 `project_daily_aggregates`，按粒度：`report_date + project_code + ad_country`。
