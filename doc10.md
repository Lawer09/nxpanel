# 项目配置与绑定接口说明（前端对接）

本文用于前端完成「项目配置 + 绑定关系配置」。

## 0. 基础说明

- 接口前缀：`/api/v3/{secure_path}`
- 鉴权：管理端登录态（`admin` 中间件）
- 返回格式（统一）：

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {}
}
```

---

## 1. 页面建议结构

建议按以下 4 个区域做前端配置：

1. 项目基础信息（项目列表/新增/编辑/状态）
2. 项目-流量账号绑定（`traffic-accounts`）
3. 项目-广告账号绑定（`ad-accounts`）
4. 项目-用户 AppId 绑定（`user-apps`，用于用户指标归属）

---

## 2. 项目基础配置

### 2.1 项目列表

`GET /projects`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| keyword | string | 否 | 按 `projectCode/projectName` 模糊搜索 |
| ownerId | int | 否 | 所属人ID |
| status | string | 否 | `active/inactive/archived` |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |

返回 `data` 示例：

```json
{
  "page": 1,
  "pageSize": 20,
  "total": 1,
  "data": [
    {
      "id": 1,
      "projectCode": "game_001",
      "projectName": "Game 001",
      "ownerName": "Tom",
      "department": "Growth",
      "status": "active",
      "remark": "test",
      "createdAt": "2026-04-29 10:00:00",
      "updatedAt": "2026-04-29 10:00:00"
    }
  ]
}
```

### 2.2 项目详情

`GET /projects/{id}`

### 2.3 新增项目

`POST /projects`

请求体：

```json
{
  "projectCode": "game_001",
  "projectName": "Game 001",
  "ownerName": "Tom",
  "department": "Growth",
  "status": "active",
  "remark": "test"
}
```

### 2.4 更新项目

`PUT /projects/{id}`

请求体（按需传递）：

```json
{
  "projectName": "Game 001 New",
  "ownerName": "Jerry",
  "department": "Ads",
  "status": "inactive",
  "remark": "updated"
}
```

### 2.5 更新项目状态

`PATCH /projects/{id}/status`

请求体：

```json
{
  "status": "active"
}
```

---

## 3. 项目-流量账号绑定

### 3.1 查询绑定

`GET /projects/{id}/traffic-accounts`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| enabled | int | 否 | `1/0` |

返回 `data[]` 关键字段：

- `id`
- `projectId`
- `projectCode`
- `trafficPlatformAccountId`
- `platformCode`
- `externalUid`
- `externalUsername`
- `bindType` (`account/sub_account`)
- `enabled`
- `remark`
- `accountName`（后端补充）

### 3.2 新增绑定

`POST /projects/{id}/traffic-accounts`

请求体：

```json
{
  "trafficPlatformAccountId": 11,
  "platformCode": "kkoip",
  "externalUid": "",
  "externalUsername": "",
  "bindType": "account",
  "enabled": 1,
  "remark": "整账号归属"
}
```

### 3.3 更新绑定

`PUT /projects/{id}/traffic-accounts/{relationId}`

请求体（按需传）：

```json
{
  "externalUid": "sub-001",
  "externalUsername": "sub name",
  "bindType": "sub_account",
  "enabled": 1,
  "remark": "子账号归属"
}
```

### 3.4 删除绑定

`DELETE /projects/{id}/traffic-accounts/{relationId}`

---

## 4. 项目-广告账号绑定

### 4.1 查询绑定

`GET /projects/{id}/ad-accounts`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| enabled | int | 否 | `1/0` |

返回 `data[]` 关键字段：

- `id`
- `projectId`
- `projectCode`
- `adPlatformAccountId`
- `platformCode`
- `externalAppId`
- `externalAdUnitId`
- `bindType` (`account/app/ad_unit`)
- `enabled`
- `remark`
- `accountName`（后端补充）

### 4.2 新增绑定

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
  "remark": "整账号归属"
}
```

### 4.3 更新绑定

`PUT /projects/{id}/ad-accounts/{relationId}`

请求体（按需传）：

```json
{
  "externalAppId": "ca-app-pub-xxx~123",
  "externalAdUnitId": "",
  "bindType": "app",
  "enabled": 1,
  "remark": "App级别归属"
}
```

### 4.4 删除绑定

`DELETE /projects/{id}/ad-accounts/{relationId}`

---

## 5. 项目-用户 AppId 绑定（用户指标归属）

> 用户指标（如 DAU、新增）通过 `v2_user.register_metadata.app_id` 与该表绑定关系做项目归属。

### 5.1 查询绑定

`GET /projects/{id}/user-apps`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| enabled | int | 否 | `1/0` |
| keyword | string | 否 | 按 `appId` 模糊搜索 |

返回 `data[]` 示例：

```json
[
  {
    "id": 8,
    "projectCode": "game_001",
    "appId": "com.demo.ios",
    "enabled": 1,
    "remark": "iOS主包",
    "createdAt": "2026-04-29 12:00:00",
    "updatedAt": "2026-04-29 12:00:00"
  }
]
```

### 5.2 新增绑定

`POST /projects/{id}/user-apps`

请求体：

```json
{
  "appId": "com.demo.ios",
  "enabled": 1,
  "remark": "iOS主包"
}
```

约束：

- 同项目下 `appId` 唯一
- `appId` 不能为空（会做 `trim`）

### 5.3 更新绑定

`PUT /projects/{id}/user-apps/{relationId}`

请求体（按需传）：

```json
{
  "appId": "com.demo.android",
  "enabled": 1,
  "remark": "Android主包"
}
```

### 5.4 删除绑定

`DELETE /projects/{id}/user-apps/{relationId}`

---

## 6. 前端下拉数据建议接口

用于绑定弹窗中的账号下拉：

### 6.1 广告账号下拉源

`GET /ad-accounts`

常用 Query：

- `sourcePlatform`（可选）
- `status=enabled`
- `keyword`（可选）
- `page=1&pageSize=200`

### 6.2 流量账号下拉源

`GET /traffic-platform/accounts`

常用 Query：

- `platformCode`（可选）
- `enabled=1`
- `keyword`（可选）
- `page=1&pageSize=200`

### 6.3 通用枚举（可选）

`GET /enum/options?types[]=plans&types[]=servers&types[]=server_groups&types[]=server_types`

---

## 7. 错误码与交互建议

- `404`：项目不存在 / 关联记录不存在
- `422`：参数校验失败、重复绑定（如“该关联已存在”“该App绑定已存在”）
- `500`：服务端异常

前端建议：

1. 新增/编辑失败优先展示后端 `msg`
2. 列表页删除后直接刷新当前列表
3. 绑定弹窗默认 `enabled=1`

---

## 8. 兼容性说明（重要）

- 旧接口 `project-app-mappings` 已下线，不再用于前端配置。
- 用户指标归属请使用 `user-apps` 接口维护 `appId` 映射。
