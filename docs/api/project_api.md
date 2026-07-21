# Project API 文档

项目基础 CRUD 接口，基于 `project_projects` 表，提供项目列表、详情、创建、编辑、状态更新功能，以及关联的流量账号/广告账号/用户App绑定管理和手动聚合触发。

---

## 路由一览

| 方法 | 路径 | 功能 | 控制器#方法 |
|------|------|------|------------|
| GET | `/projects/` | 项目列表 | `ProjectController::index` |
| GET | `/projects/detail` | 项目详情 | `ProjectController::detail` |
| POST | `/projects/create` | 创建项目 | `ProjectController::store` |
| POST | `/projects/update` | 编辑项目 | `ProjectController::update` |
| POST | `/projects/update-status` | 更新项目状态 | `ProjectController::updateStatus` |
| POST | `/projects/batch-update-ad-status` | 批量更新项目投放状态 | `ProjectController::batchUpdateAdStatus` |
| POST | `/projects/batch-update-app-platform` | 批量更新项目应用平台 | `ProjectController::batchUpdateAppPlatform` |
| GET | `/projects/project-codes` | 项目代号列表 | `ProjectController::projectCodes` |
| POST | `/projects/aggregate` | 手动聚合（同步） | `ProjectController::aggregate` |
| POST | `/projects/aggregate-async` | 手动聚合（异步） | `ProjectController::aggregateAsync` |
| GET | `/projects/traffic-accounts` | 流量账号列表 | `ProjectTrafficAccountController::index` |
| POST | `/projects/traffic-accounts/create` | 新增流量账号关联 | `ProjectTrafficAccountController::store` |
| POST | `/projects/traffic-accounts/update` | 修改流量账号关联 | `ProjectTrafficAccountController::update` |
| POST | `/projects/traffic-accounts/delete` | 删除流量账号关联 | `ProjectTrafficAccountController::destroy` |
| GET | `/projects/ad-accounts` | 广告账号列表 | `ProjectAdAccountController::index` |
| POST | `/projects/ad-accounts/create` | 新增广告账号关联 | `ProjectAdAccountController::store` |
| POST | `/projects/ad-accounts/update` | 修改广告账号关联 | `ProjectAdAccountController::update` |
| POST | `/projects/ad-accounts/delete` | 删除广告账号关联 | `ProjectAdAccountController::destroy` |
| GET | `/projects/user-apps` | 用户App绑定列表 | `ProjectUserAppMapController::index` |
| GET | `/projects/user-apps/mappings` | 项目代号与包名映射 | `ProjectUserAppMapController::mappings` |
| POST | `/projects/user-apps/create` | 新增用户App绑定 | `ProjectUserAppMapController::store` |
| POST | `/projects/user-apps/update` | 修改用户App绑定 | `ProjectUserAppMapController::update` |
| POST | `/projects/user-apps/delete` | 删除用户App绑定 | `ProjectUserAppMapController::destroy` |
| GET | `/projects/app-infos` | 应用信息列表 | `ProjectAppInfoController::index` |
| GET | `/projects/app-infos/detail` | 应用信息详情 | `ProjectAppInfoController::detail` |
| POST | `/projects/app-infos/create` | 新增应用信息 | `ProjectAppInfoController::store` |
| POST | `/projects/app-infos/update` | 修改应用信息 | `ProjectAppInfoController::update` |
| POST | `/projects/app-infos/delete` | 删除应用信息 | `ProjectAppInfoController::destroy` |
| GET | `/projects/version-records` | 项目版本记录列表 | `ProjectVersionRecordController::index` |
| GET | `/projects/version-records/detail` | 项目版本记录详情 | `ProjectVersionRecordController::detail` |
| POST | `/projects/version-records/create` | 新增项目版本记录 | `ProjectVersionRecordController::store` |
| POST | `/projects/version-records/batch-create` | 批量新增项目版本记录 | `ProjectVersionRecordController::batchStore` |
| POST | `/projects/version-records/update` | 修改项目版本记录 | `ProjectVersionRecordController::update` |
| POST | `/projects/version-records/delete` | 删除项目版本记录 | `ProjectVersionRecordController::destroy` |

所有路径前缀均为 `/api/v3/admin/{securePath}`。

---

## 1. 项目列表

- **方法/路径**：`GET /api/v3/admin/{securePath}/projects/`
- **控制器**：`ProjectController::index`
- **Request**：`ProjectFetchRequest`
- **数据来源**：`project_projects`

### 1.1 请求参数（query）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| keyword | string | 否 | 模糊搜索（匹配 projectCode / projectName / 关联用户 App 的 app_id） |
| status | string | 否 | 筛选：`active` / `inactive` / `archived` |
| adStatus | string | 否 | 投放状态筛选，自定义字符串 |
| appPlatform | string | 否 | 应用平台筛选，自定义字符串 |
| packageName | string | 否 | 项目包名精确筛选 |
| developerGmail | string | 否 | 开发者 Gmail 精确筛选 |
| ownerId | int | 否 | 按拥有者 ID 筛选 |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |

### 1.2 返回结构

```json
{
  "data": [
    {
      "id": 1,
      "projectCode": "P001",
      "projectName": "测试项目",
      "ownerName": "张三",
      "department": "技术部",
      "status": "active",
      "adStatus": "running",
      "appPlatform": "android",
      "adspowerEnv": "env-placeholder",
      "developerGmail": "developer@example.com",
      "appName": "Example VPN",
      "packageName": "com.example.vpn",
      "domainInfoStatus": "completed",
      "admobPubId": "pub-placeholder",
      "domainUrl": "https://example.com",
      "privacyPolicyUrl": "https://example.com/privacy.html",
      "termsUrl": "https://example.com/terms.html",
      "facebookInfoStatus": "completed",
      "facebookAppId": "facebook-app-id-placeholder",
      "facebookAppToken": "facebook-token-placeholder",
      "facebookKeyHash": "facebook-key-hash-placeholder",
      "facebookClassName": "facebook-class-placeholder",
      "admobAccountStatus": "completed",
      "admobAppId": "admob-app-id-placeholder",
      "admobAdIds": "admob-ad-ids-placeholder",
      "admobAppAdsTxt": "app-ads-placeholder",
      "firebaseConfigNote": "firebase-config-placeholder",
      "yandexAccount": "yandex-account-placeholder",
      "yandexAdIds": "yandex-ad-ids-placeholder",
      "yandexAppAdsTxt": "yandex-app-ads-placeholder",
      "storePageUrl": "https://play.google.com/store/apps/details?id=com.example.vpn",
      "remark": null,
      "createdAt": "2026-05-12T00:00:00.000Z",
      "updatedAt": "2026-05-12T00:00:00.000Z",
      "trafficAccounts": [
        {
          "id": 1,
          "trafficPlatformAccountId": 1,
          "platformCode": "google",
          "externalUid": null,
          "externalUsername": null,
          "bindType": "account",
          "enabled": 1,
          "remark": null,
          "createdAt": "2026-05-12T00:00:00.000Z",
          "updatedAt": "2026-05-12T00:00:00.000Z"
        }
      ],
      "adAccounts": [
        {
          "id": 1,
          "adPlatformAccountId": 1,
          "platformCode": "admob",
          "externalAppId": null,
          "externalAdUnitId": null,
          "bindType": "account",
          "enabled": 1,
          "remark": null,
          "createdAt": "2026-05-12T00:00:00.000Z",
          "updatedAt": "2026-05-12T00:00:00.000Z"
        }
      ],
      "userApps": [
        {
          "id": 1,
          "appId": "com.example.app",
          "appLink": "https://apps.apple.com/app/example",
          "enabled": 1,
          "remark": null,
          "createdAt": "2026-05-12T00:00:00.000Z",
          "updatedAt": "2026-05-12T00:00:00.000Z"
        }
      ],
      "appInfos": [
        {
          "id": 1,
          "appId": "com.example.app",
          "appName": "Example App",
          "platform": "android",
          "downloadCount": 12345,
          "downloadData": [
            {"date": "2026-07-05", "downloads": 100}
          ],
          "iconUrl": "https://example.com/icon.png",
          "chartUrl": "https://example.com/chart.png",
          "imageUrls": ["https://example.com/screenshot.png"],
          "storeUrl": "https://play.google.com/store/apps/details?id=com.example.app",
          "enabled": 1,
          "remark": null,
          "createdAt": "2026-05-12T00:00:00.000Z",
          "updatedAt": "2026-05-12T00:00:00.000Z"
        }
      ]
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 20
}
```

### 1.3 data[] 字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 项目 ID |
| projectCode | string | 项目代号 |
| projectName | string | 项目名称 |
| ownerName | string/null | 负责人 |
| department | string/null | 所属部门 |
| status | string | 状态：`active` / `inactive` / `archived` |
| adStatus | string/null | 投放状态，自定义字符串 |
| appPlatform | string/null | 应用平台，自定义字符串 |
| adspowerEnv | string/null | Adspower 环境 |
| developerGmail | string/null | 开发者 Gmail |
| appName | string/null | 应用名称 |
| packageName | string/null | 项目包名 |
| domainInfoStatus | string/null | 域名信息状态 |
| admobPubId | string/null | Admob pub id |
| domainUrl | string/null | 域名 URL |
| privacyPolicyUrl | string/null | 隐私协议 URL |
| termsUrl | string/null | 服务条款 URL |
| facebookInfoStatus | string/null | FB 信息状态 |
| facebookAppId | string/null | Facebook 应用 ID |
| facebookAppToken | string/null | Facebook 应用 Token |
| facebookKeyHash | string/null | Facebook 秘钥散列 |
| facebookClassName | string/null | Facebook 类名 |
| admobAccountStatus | string/null | Admob 账号状态 |
| admobAppId | string/null | Admob 应用 ID |
| admobAdIds | string/null | Admob 广告 ID 配置，支持多行文本 |
| admobAppAdsTxt | string/null | Admob app-ads.txt 内容 |
| firebaseConfigNote | string/null | Firebase 配置说明 |
| yandexAccount | string/null | Yandex 账号 |
| yandexAdIds | string/null | Yandex 广告 ID 配置，支持多行文本 |
| yandexAppAdsTxt | string/null | Yandex app-ads.txt 内容 |
| storePageUrl | string/null | 商店页链接 |
| remark | string/null | 备注 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |
| trafficAccounts | array | 关联的流量账号列表 |
| adAccounts | array | 关联的广告账号列表 |
| userApps | array | 关联的用户 App 绑定列表 |
| appInfos | array | 通过 `project_user_app_map.app_id` 间接关联的应用信息列表 |

### 1.4 trafficAccounts[] 字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 关联记录 ID |
| trafficPlatformAccountId | int | 流量平台账号 ID |
| platformCode | string | 流量平台编码 |
| externalUid | string/null | 三方子账号 ID |
| externalUsername | string/null | 三方子账号名称 |
| bindType | string | 绑定类型：`account` / `sub_account` |
| enabled | int | 是否启用（1=启用） |
| remark | string/null | 备注 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

### 1.5 adAccounts[] 字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 关联记录 ID |
| adPlatformAccountId | int | 广告变现平台账号 ID |
| platformCode | string | 广告平台编码 |
| externalAppId | string/null | 广告平台应用 ID |
| externalAdUnitId | string/null | 广告位 ID |
| bindType | string | 绑定类型：`account` / `app` / `ad_unit` |
| enabled | int | 是否启用（1=启用） |
| remark | string/null | 备注 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

### 1.6 userApps[] 字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 绑定记录 ID |
| appId | string | 用户注册 metadata 中的 app_id |
| appLink | string/null | App 跳转或下载链接 |
| enabled | int | 是否启用（1=启用） |
| remark | string/null | 备注 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

### 1.7 appInfos[] 字段说明

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 应用信息记录 ID |
| appId | string | 应用 ID，使用项目用户 App 绑定中的 `app_id` 口径 |
| appName | string/null | 应用名称 |
| platform | string/null | 应用平台 |
| downloadCount | int | 应用累计下载量 |
| downloadData | object[] | 应用下载数据，可保存趋势或渠道明细等结构化数据 |
| iconUrl | string/null | 应用图标 URL |
| chartUrl | string/null | 图表或截图 URL |
| imageUrls | string[] | 其他应用图片 URL 列表 |
| storeUrl | string/null | 应用商店 URL |
| enabled | int | 是否启用，1=启用，0=停用 |
| remark | string/null | 备注 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

---

## 2. 项目详情

- **方法/路径**：`GET /api/v3/admin/{securePath}/projects/detail`
- **控制器**：`ProjectController::detail`
- **Request**：`IdRequest`

### 2.1 请求参数（query）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 项目 ID |

### 2.2 返回字段

同 1.3 data[] 字段说明，单条记录。

### 2.3 错误

| HTTP 状态码 | 说明 |
| --- | --- |
| 404 | 项目不存在 |

---

## 3. 创建项目

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/create`
- **控制器**：`ProjectController::store`
- **Request**：`ProjectSaveRequest`

### 3.1 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| projectCode | string | 是 | 项目代号，唯一 |
| projectName | string | 是 | 项目名称 |
| ownerName | string | 否 | 负责人 |
| department | string | 否 | 所属部门 |
| status | string | 否 | 默认 `active`，可选：`active` / `inactive` / `archived` |
| adStatus | string | 否 | 投放状态，自定义字符串，最大 50 字符；未传时默认 `未上线` |
| appPlatform | string | 否 | 应用平台，自定义字符串，最大 50 字符 |
| adspowerEnv | string | 否 | Adspower 环境，最大 100 字符 |
| developerGmail | string | 否 | 开发者 Gmail，最大 191 字符 |
| appName | string | 否 | 应用名称，最大 191 字符 |
| packageName | string | 否 | 项目包名，最大 191 字符 |
| domainInfoStatus | string | 否 | 域名信息状态，最大 50 字符 |
| admobPubId | string | 否 | Admob pub id，最大 100 字符 |
| domainUrl | string | 否 | 域名 URL，最大 255 字符 |
| privacyPolicyUrl | string | 否 | 隐私协议 URL，最大 255 字符 |
| termsUrl | string | 否 | 服务条款 URL，最大 255 字符 |
| facebookInfoStatus | string | 否 | FB 信息状态，最大 50 字符 |
| facebookAppId | string | 否 | Facebook 应用 ID，最大 100 字符 |
| facebookAppToken | string | 否 | Facebook 应用 Token，最大 255 字符 |
| facebookKeyHash | string | 否 | Facebook 秘钥散列，最大 255 字符 |
| facebookClassName | string | 否 | Facebook 类名，最大 191 字符 |
| admobAccountStatus | string | 否 | Admob 账号状态，最大 50 字符 |
| admobAppId | string | 否 | Admob 应用 ID，最大 100 字符 |
| admobAdIds | string | 否 | Admob 广告 ID 配置，支持多行文本 |
| admobAppAdsTxt | string | 否 | Admob app-ads.txt 内容 |
| firebaseConfigNote | string | 否 | Firebase 配置说明 |
| yandexAccount | string | 否 | Yandex 账号，最大 191 字符 |
| yandexAdIds | string | 否 | Yandex 广告 ID 配置，支持多行文本 |
| yandexAppAdsTxt | string | 否 | Yandex app-ads.txt 内容 |
| storePageUrl | string | 否 | 商店页链接，最大 255 字符 |
| remark | string | 否 | 备注 |

### 3.2 返回字段

同 1.3 data[] 字段说明，新增后的完整记录。

### 3.3 错误

| HTTP 状态码 | 说明 |
| --- | --- |
| 422 | 项目代号已存在 |

---

## 4. 编辑项目

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/update`
- **控制器**：`ProjectController::update`
- **Request**：`ProjectUpdateRequest`

### 4.1 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 项目 ID |
| projectName | string | 否 | 项目名称 |
| ownerName | string | 否 | 负责人 |
| department | string | 否 | 所属部门 |
| status | string | 否 | `active` / `inactive` / `archived` |
| adStatus | string | 否 | 投放状态，自定义字符串，最大 50 字符；传 `null` 可清空 |
| appPlatform | string | 否 | 应用平台，自定义字符串，最大 50 字符；传 `null` 可清空 |
| adspowerEnv | string | 否 | Adspower 环境，最大 100 字符；传 `null` 可清空 |
| developerGmail | string | 否 | 开发者 Gmail，最大 191 字符；传 `null` 可清空 |
| appName | string | 否 | 应用名称，最大 191 字符；传 `null` 可清空 |
| packageName | string | 否 | 项目包名，最大 191 字符；传 `null` 可清空 |
| domainInfoStatus | string | 否 | 域名信息状态，最大 50 字符；传 `null` 可清空 |
| admobPubId | string | 否 | Admob pub id，最大 100 字符；传 `null` 可清空 |
| domainUrl | string | 否 | 域名 URL，最大 255 字符；传 `null` 可清空 |
| privacyPolicyUrl | string | 否 | 隐私协议 URL，最大 255 字符；传 `null` 可清空 |
| termsUrl | string | 否 | 服务条款 URL，最大 255 字符；传 `null` 可清空 |
| facebookInfoStatus | string | 否 | FB 信息状态，最大 50 字符；传 `null` 可清空 |
| facebookAppId | string | 否 | Facebook 应用 ID，最大 100 字符；传 `null` 可清空 |
| facebookAppToken | string | 否 | Facebook 应用 Token，最大 255 字符；传 `null` 可清空 |
| facebookKeyHash | string | 否 | Facebook 秘钥散列，最大 255 字符；传 `null` 可清空 |
| facebookClassName | string | 否 | Facebook 类名，最大 191 字符；传 `null` 可清空 |
| admobAccountStatus | string | 否 | Admob 账号状态，最大 50 字符；传 `null` 可清空 |
| admobAppId | string | 否 | Admob 应用 ID，最大 100 字符；传 `null` 可清空 |
| admobAdIds | string | 否 | Admob 广告 ID 配置，支持多行文本；传 `null` 可清空 |
| admobAppAdsTxt | string | 否 | Admob app-ads.txt 内容；传 `null` 可清空 |
| firebaseConfigNote | string | 否 | Firebase 配置说明；传 `null` 可清空 |
| yandexAccount | string | 否 | Yandex 账号，最大 191 字符；传 `null` 可清空 |
| yandexAdIds | string | 否 | Yandex 广告 ID 配置，支持多行文本；传 `null` 可清空 |
| yandexAppAdsTxt | string | 否 | Yandex app-ads.txt 内容；传 `null` 可清空 |
| storePageUrl | string | 否 | 商店页链接，最大 255 字符；传 `null` 可清空 |
| remark | string | 否 | 备注 |

### 4.2 返回字段

同 1.3 data[] 字段说明，修改后的完整记录。

### 4.3 错误

| HTTP 状态码 | 说明 |
| --- | --- |
| 404 | 项目不存在 |

---

## 5. 更新项目状态

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/update-status`
- **控制器**：`ProjectController::updateStatus`
- **Request**：`ProjectUpdateStatusRequest`

### 5.1 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 项目 ID |
| status | string | 是 | `active` / `inactive` / `archived` |

### 5.2 返回字段

同 1.3 data[] 字段说明，修改后的完整记录。

### 5.3 错误

| HTTP 状态码 | 说明 |
| --- | --- |
| 404 | 项目不存在 |

### 5.4 批量更新项目投放状态

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/batch-update-ad-status`
- **控制器**：`ProjectController::batchUpdateAdStatus`
- **Request**：`ProjectBatchUpdateAdStatusRequest`
- **说明**：批量更新 `project_projects.ad_status`，不修改系统项目状态 `status`

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| ids | int[] | 是 | 项目 ID 数组，单次最多 500 个，不能重复 |
| adStatus | string/null | 否 | 投放状态，自定义字符串，最大 50 字符；传 `null` 可清空 |

#### 请求示例

```json
{
  "ids": [1, 2, 3],
  "adStatus": "running"
}
```

#### 返回示例

```json
{
  "requested": 3,
  "updated": 3,
  "missingIds": []
}
```

### 5.5 批量更新项目应用平台
- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/batch-update-app-platform`
- **控制器**：`ProjectController::batchUpdateAppPlatform`
- **Request**：`ProjectBatchUpdateAppPlatformRequest`
- **说明**：批量更新 `project_projects.app_platform`

#### 请求参数（body JSON）
| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| ids | int[] | 是 | 项目 ID 数组，单次最多 500 个，不能重复 |
| appPlatform | string/null | 否 | 应用平台，自定义字符串，最大 50 字符；传 `null` 可清空 |

#### 请求示例

```json
{
  "ids": [1, 2, 3],
  "appPlatform": "android"
}
```

#### 返回示例

```json
{
  "requested": 3,
  "updated": 3,
  "missingIds": []
}
```

---

## 6. 流量账号关联管理

### 6.1 查询已关联流量账号

- **方法/路径**：`GET /api/v3/admin/{securePath}/projects/traffic-accounts`
- **控制器**：`ProjectTrafficAccountController::index`
- **数据来源**：`project_traffic_platform_accounts`

#### 请求参数（query）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| project_id | int | 是 | 项目 ID |

#### 返回结构

```json
{
  "data": [
    {
      "id": 1,
      "trafficPlatformAccountId": 1,
      "platformCode": "google",
      "externalUid": null,
      "externalUsername": null,
      "bindType": "account",
      "enabled": 1,
      "remark": null,
      "createdAt": "2026-05-12T00:00:00.000Z",
      "updatedAt": "2026-05-12T00:00:00.000Z"
    }
  ]
}
```

### 6.2 新增流量账号关联

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/traffic-accounts/create`
- **控制器**：`ProjectTrafficAccountController::store`
- **Request**：`ProjectTrafficAccountStoreRequest`

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| projectId | int | 是 | 项目 ID |
| trafficPlatformAccountId | int | 是 | 流量平台账号 ID |
| platformCode | string | 是 | 流量平台编码 |
| externalUid | string | 否 | 三方子账号 ID |
| externalUsername | string | 否 | 三方子账号名称 |
| bindType | string | 否 | 默认 `account` |
| enabled | int | 否 | 默认 1 |
| remark | string | 否 | 备注 |

### 6.3 修改流量账号关联

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/traffic-accounts/update`
- **控制器**：`ProjectTrafficAccountController::update`
- **Request**：`ProjectTrafficAccountUpdateRequest`

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 关联记录 ID |
| projectId | int | 是 | 项目 ID |
| enabled | int | 否 | 是否启用 |
| remark | string | 否 | 备注 |

### 6.4 删除流量账号关联

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/traffic-accounts/delete`
- **控制器**：`ProjectTrafficAccountController::destroy`
- **Request**：`ProjectResourceIdRequest`

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 关联记录 ID |
| projectId | int | 是 | 项目 ID |

---

## 7. 广告账号关联管理

### 7.1 查询已关联广告账号

- **方法/路径**：`GET /api/v3/admin/{securePath}/projects/ad-accounts`
- **控制器**：`ProjectAdAccountController::index`
- **数据来源**：`project_ad_platform_accounts`

#### 请求参数（query）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| project_id | int | 是 | 项目 ID |

#### 返回结构

```json
{
  "data": [
    {
      "id": 1,
      "adPlatformAccountId": 1,
      "platformCode": "admob",
      "externalAppId": null,
      "externalAdUnitId": null,
      "bindType": "account",
      "enabled": 1,
      "remark": null,
      "createdAt": "2026-05-12T00:00:00.000Z",
      "updatedAt": "2026-05-12T00:00:00.000Z"
    }
  ]
}
```

### 7.2 新增广告账号关联

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/ad-accounts/create`
- **控制器**：`ProjectAdAccountController::store`
- **Request**：`ProjectAdAccountStoreRequest`

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| projectId | int | 是 | 项目 ID |
| adPlatformAccountId | int | 是 | 广告变现平台账号 ID |
| platformCode | string | 是 | 广告平台编码 |
| externalAppId | string | 否 | 广告平台应用 ID |
| externalAdUnitId | string | 否 | 广告位 ID |
| bindType | string | 否 | 默认 `account` |
| enabled | int | 否 | 默认 1 |
| remark | string | 否 | 备注 |

### 7.3 修改广告账号关联

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/ad-accounts/update`
- **控制器**：`ProjectAdAccountController::update`
- **Request**：`ProjectAdAccountUpdateRequest`

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 关联记录 ID |
| projectId | int | 是 | 项目 ID |
| enabled | int | 否 | 是否启用 |
| remark | string | 否 | 备注 |

### 7.4 删除广告账号关联

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/ad-accounts/delete`
- **控制器**：`ProjectAdAccountController::destroy`
- **Request**：`ProjectResourceIdRequest`

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 关联记录 ID |
| projectId | int | 是 | 项目 ID |

---

## 8. 用户 App 绑定管理

### 8.1 查询已绑定用户 App

- **方法/路径**：`GET /api/v3/admin/{securePath}/projects/user-apps`
- **控制器**：`ProjectUserAppMapController::index`
- **数据来源**：`project_user_app_map`

#### 请求参数（query）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| project_id | int | 是 | 项目 ID |

#### 返回结构

```json
{
  "data": [
    {
      "id": 1,
      "appId": "com.example.app",
      "appLink": "https://apps.apple.com/app/example",
      "enabled": 1,
      "remark": null,
      "createdAt": "2026-05-12T00:00:00.000Z",
      "updatedAt": "2026-05-12T00:00:00.000Z"
    }
  ]
}
```

### 8.2 项目代号与包名映射

- **方法/路径**：`GET /api/v3/admin/{securePath}/projects/user-apps/mappings`
- **控制器**：`ProjectUserAppMapController::mappings`
- **Request**：`ProjectUserAppMapMappingRequest`
- **数据来源**：`project_user_app_map`
- **说明**：按 `project_code` 分组返回对应的 `app_id` 列表，字段名为 `packageNames`。默认只返回 `enabled = 1` 的映射，和 AID 封禁规则 `projectCodes` 扩展包名的口径一致。

#### 请求参数（query）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| projectCode | string | 否 | 按项目代号精确筛选 |
| keyword | string | 否 | 按项目代号或包名模糊筛选 |
| enabled | int | 否 | 启用状态，`0` 或 `1`；未传时默认 `1` |
| includeDisabled | bool | 否 | 是否包含禁用映射；传 `1/true` 时不再默认过滤 `enabled = 1`，若同时传 `enabled` 则仍按 `enabled` 筛选 |

#### 返回示例

```json
{
  "data": [
    {
      "projectCode": "rocket",
      "packageNames": [
        "com.rocket.vpn",
        "com.rocket.secure"
      ],
      "appCount": 2
    }
  ]
}
```

### 8.3 新增用户 App 绑定

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/user-apps/create`
- **控制器**：`ProjectUserAppMapController::store`
- **Request**：`ProjectUserAppMapStoreRequest`

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| projectId | int | 是 | 项目 ID |
| appId | string | 是 | 用户注册 metadata 中的 app_id |
| appLink | string | 否 | App 跳转或下载链接，最大长度 500 |
| enabled | int | 否 | 默认 1 |
| remark | string | 否 | 备注 |

### 8.4 修改用户 App 绑定

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/user-apps/update`
- **控制器**：`ProjectUserAppMapController::update`
- **Request**：`ProjectUserAppMapUpdateRequest`

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 绑定记录 ID |
| projectId | int | 是 | 项目 ID |
| appId | string | 否 | 用户注册 metadata 中的 app_id |
| appLink | string | 否 | App 跳转或下载链接，最大长度 500 |
| enabled | int | 否 | 是否启用 |
| remark | string | 否 | 备注 |

### 8.5 删除用户 App 绑定

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/user-apps/delete`
- **控制器**：`ProjectUserAppMapController::destroy`
- **Request**：`ProjectResourceIdRequest`

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 绑定记录 ID |
| projectId | int | 是 | 项目 ID |

---

## 9. 手动触发日聚合

### 9.1 同步聚合

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/aggregate`
- **控制器**：`ProjectController::aggregate`
- **说明**：通过 Artisan command `project:aggregate-daily` 同步执行日数据聚合（等待完成）

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期（Y-m-d） |
| endDate | string | 是 | 结束日期（Y-m-d），须 >= startDate |
| projectId | int | 否 | 项目 ID；传入后仅重算该项目，不影响同日期其他项目聚合结果 |

### 9.2 异步聚合

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/aggregate-async`
- **控制器**：`ProjectController::aggregateAsync`
- **说明**：将聚合任务 `AggregateProjectDailyJob` 投递到队列异步执行

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期（Y-m-d） |
| endDate | string | 是 | 结束日期（Y-m-d），须 >= startDate |
| projectId | int | 否 | 项目 ID；传入后仅异步重算该项目，不影响同日期其他项目聚合结果 |

#### 返回结构

```json
{
  "accepted": true,
  "triggerId": "uuid-string",
  "startDate": "2026-05-01",
  "endDate": "2026-05-15",
  "projectId": 12,
  "status": "queued"
}
```

---

## 应用信息管理

应用信息基于 `app_infos` 表维护，按 `appId` 唯一。项目列表和报表中的 `appInfos` 不直接关联项目表，而是通过现有 `project_user_app_map.project_code -> app_id` 映射加载。

### 应用信息列表

- **方法/路径**：`GET /api/v3/admin/{securePath}/projects/app-infos`
- **控制器**：`ProjectAppInfoController::index`
- **Request**：`ProjectAppInfoIndexRequest`

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| projectCode | string | 否 | 通过项目代号查找已绑定的 appId 后筛选 |
| projectId | int | 否 | 通过项目 ID 查找项目代号，再按已绑定 appId 筛选 |
| appId | string | 否 | 按应用 ID 精确筛选 |
| enabled | int | 否 | `1` 启用，`0` 停用 |
| keyword | string | 否 | 模糊匹配 `appId/appName/platform` |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |

### 新增/修改应用信息

- **新增**：`POST /api/v3/admin/{securePath}/projects/app-infos/create`
- **修改**：`POST /api/v3/admin/{securePath}/projects/app-infos/update`
- **删除**：`POST /api/v3/admin/{securePath}/projects/app-infos/delete`
- **详情**：`GET /api/v3/admin/{securePath}/projects/app-infos/detail?id=1`

新增时必须传 `appId`；修改时必须传 `id`，其他字段未传不修改。删除和详情只需要 `id`。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| appId | string | 应用 ID，最大 255 字符 |
| appName | string/null | 应用名称，最大 191 字符 |
| platform | string/null | 应用平台，最大 50 字符 |
| downloadCount | int | 累计下载量，最小 0 |
| downloadData | object[] | 应用下载数据，JSON 数组 |
| iconUrl | string/null | 应用图标 URL，最大 255 字符 |
| chartUrl | string/null | 图表或截图 URL，最大 255 字符 |
| imageUrls | string[] | 其他应用图片 URL 列表，单项最大 255 字符 |
| storeUrl | string/null | 应用商店 URL，最大 255 字符 |
| enabled | int | `1` 启用，`0` 停用 |
| remark | string/null | 备注，最大 255 字符 |

## 项目版本记录管理

项目版本记录基于 `project_version_records` 表维护，按项目挂载。项目列表和详情接口不内嵌版本记录，前端按需调用以下独立接口。

### 项目版本记录列表

- **方法/路径**：`GET /api/v3/admin/{securePath}/projects/version-records`
- **控制器**：`ProjectVersionRecordController::index`
- **Request**：`ProjectVersionRecordIndexRequest`

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| projectId | int | 否 | 按项目 ID 精确筛选 |
| projectCode | string | 否 | 按项目代号快照精确筛选 |
| keyword | string | 否 | 模糊匹配 `version/versionName/content` |
| releaseTimeFrom | string | 否 | 上线时间起始值，日期或日期时间 |
| releaseTimeTo | string | 否 | 上线时间结束值，必须大于等于 `releaseTimeFrom` |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |

#### 返回示例

```json
{
  "page": 1,
  "pageSize": 20,
  "total": 1,
  "data": [
    {
      "id": 1,
      "projectId": 12,
      "projectCode": "A001",
      "version": "1.0.0",
      "versionName": "首发版本",
      "content": "首次上线",
      "releaseTime": "2026-07-17T10:00:00.000000Z",
      "remark": null,
      "createdAt": "2026-07-17T10:00:00.000000Z",
      "updatedAt": "2026-07-17T10:00:00.000000Z"
    }
  ]
}
```

### 新增/修改项目版本记录

- **新增**：`POST /api/v3/admin/{securePath}/projects/version-records/create`
- **批量新增**：`POST /api/v3/admin/{securePath}/projects/version-records/batch-create`
- **修改**：`POST /api/v3/admin/{securePath}/projects/version-records/update`
- **删除**：`POST /api/v3/admin/{securePath}/projects/version-records/delete`
- **详情**：`GET /api/v3/admin/{securePath}/projects/version-records/detail?id=1`

新增时必须传 `projectId/version/content/releaseTime`，可选传 `versionName`；服务端根据 `projectId` 自动写入 `projectCode` 快照。批量新增请求体为 `items` 数组，每项字段与单条新增一致，整批在事务中创建，任一条失败则整批不写入。修改时必须传 `id`，其他字段未传不修改，修改 `projectId` 时会同步刷新 `projectCode` 快照。删除和详情只需要 `id`。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 版本记录 ID，修改/删除/详情时使用 |
| projectId | int | 项目 ID，新增必填，修改可选 |
| version | string | 版本号，最大 100 字符 |
| versionName | string/null | 版本名称，最大 191 字符 |
| content | string | 版本内容 |
| releaseTime | string | 上线时间，日期或日期时间 |
| remark | string/null | 备注，最大 255 字符 |

#### 批量新增请求示例

```json
{
  "items": [
    {
      "projectId": 12,
      "version": "1.0.0",
      "versionName": "首发版本",
      "content": "首次上线",
      "releaseTime": "2026-07-21 10:00:00",
      "remark": null
    },
    {
      "projectId": 12,
      "version": "1.0.1",
      "versionName": "修复版本",
      "content": "修复已知问题",
      "releaseTime": "2026-07-21 12:00:00"
    }
  ]
}
```

#### 批量新增返回示例

```json
{
  "created": 2,
  "total": 2,
  "items": [
    {
      "id": 1,
      "projectId": 12,
      "projectCode": "A001",
      "version": "1.0.0",
      "versionName": "首发版本",
      "content": "首次上线",
      "releaseTime": "2026-07-21T10:00:00.000000Z",
      "remark": null,
      "createdAt": "2026-07-21T10:00:00.000000Z",
      "updatedAt": "2026-07-21T10:00:00.000000Z"
    },
    {
      "id": 2,
      "projectId": 12,
      "projectCode": "A001",
      "version": "1.0.1",
      "versionName": "修复版本",
      "content": "修复已知问题",
      "releaseTime": "2026-07-21T12:00:00.000000Z",
      "remark": null,
      "createdAt": "2026-07-21T12:00:00.000000Z",
      "updatedAt": "2026-07-21T12:00:00.000000Z"
    }
  ]
}
```

## 通用说明

- 路径中的 `{securePath}` 由 `admin_setting('secure_path', ...)` 动态生成
- 所有请求/返回参数均为 **camelCase**
- 分页接口统一返回 `data` / `total` / `page` / `pageSize`
- 项目代号（projectCode）在创建接口中全局唯一，重复返回 422

### 5.6 批量更新项目部门

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/batch-update-department`
- **控制器**：`ProjectController::batchUpdateDepartment`
- **Request**：`ProjectBatchUpdateDepartmentRequest`
- **说明**：批量更新 `project_projects.department`，传 `null` 可清空部门。

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| ids | int[] | 是 | 项目 ID 数组，单次最多 500 个，不能重复 |
| department | string/null | 否 | 所属部门，最大 100 字符；传 `null` 可清空 |

#### 请求示例

```json
{
  "ids": [1, 2, 3],
  "department": "技术部"
}
```

#### 返回示例

```json
{
  "requested": 3,
  "updated": 3,
  "missingIds": []
}
```

### 5.7 部门列表

- **方法/路径**：`GET /api/v3/admin/{securePath}/projects/departments`
- **控制器**：`ProjectController::departments`
- **说明**：从现有 `project_projects.department` 数据中查询非空部门，去重后按部门名称升序返回；结果缓存 300 秒，创建/编辑项目、批量保存项目、批量更新部门时会自动失效缓存；不新增独立部门配置表。

#### 返回示例

```json
{
  "data": ["产品部", "技术部", "运营部"]
}
```
### 5.8 项目代号列表

- **方法/路径**：`GET /api/v3/admin/{securePath}/projects/project-codes`
- **控制器**：`ProjectController::projectCodes`
- **说明**：从现有 `project_projects.project_code` 数据中查询非空项目代号，去重后按项目代号升序返回；结果缓存 300 秒，创建项目、批量保存新增项目时会自动失效缓存。

#### 返回示例

```json
{
  "data": ["A001", "A002", "P001"]
}
```

### 5.9 批量保存项目

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/batch-save`
- **控制器**：`ProjectController::batchSave`
- **Request**：`ProjectBatchSaveRequest`
- **说明**：按 `projectCode` 判断项目是否存在；存在则更新，不存在则创建。该接口只处理 `project_projects` 主表字段，不处理流量账号、广告账号、用户 App 绑定等关联内容。

#### 请求参数（body JSON）

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| items | object[] | 是 | 项目数组；接口接收任意数量，服务端每 100 条分批处理 |
| items[].projectCode | string | 是 | 项目代号，同一请求内不能重复 |
| items[].projectName | string | 新建必填 | 项目名称；更新已有项目时可不传，传了则必须非空 |
| items[].ownerName | string/null | 否 | 负责人；更新时未传不修改，传 `null` 可清空 |
| items[].department | string/null | 否 | 部门；更新时未传不修改，传 `null` 可清空 |
| items[].status | string | 否 | `active` / `inactive` / `archived`；新建默认 `active` |
| items[].adStatus | string/null | 否 | 投放状态；新建时未传默认 `未上线`；更新时未传不修改，传 `null` 可清空 |
| items[].appPlatform | string/null | 否 | 应用平台；更新时未传不修改，传 `null` 可清空 |
| items[].remark | string/null | 否 | 备注；更新时未传不修改，传 `null` 可清空 |

其他项目元数据字段与创建/编辑项目接口一致，例如 `adspowerEnv`、`developerGmail`、`appName`、`packageName`、`domainInfoStatus`、`domainUrl` 等；更新时同样遵循“未传不修改，传 `null` 清空 nullable 字段”。

#### 请求示例

```json
{
  "items": [
    {
      "projectCode": "A001",
      "projectName": "Project A",
      "ownerName": "Alice",
      "department": "技术部",
      "status": "active",
      "adStatus": "未上线",
      "appPlatform": "android",
      "packageName": "com.example.app",
      "remark": "optional"
    },
    {
      "projectCode": "A002",
      "department": null
    }
  ]
}
```

#### 返回示例

```json
{
  "created": 1,
  "updated": 1,
  "total": 2,
  "items": [
    {
      "projectCode": "A001",
      "action": "created",
      "id": 101
    },
    {
      "projectCode": "A002",
      "action": "updated",
      "id": 102
    }
  ]
}
```

### 5.10 商店页上线状态自动检测

- **命令**：`project:check-store-online-status --limit=200`
- **调度**：每 30 分钟执行一次，使用 `onOneServer()` 和 `withoutOverlapping(25)` 防止重复调度。
- **检测范围**：`project_projects.ad_status = 未上线` 且 `store_page_url` 非空的项目。
- **检测方式**：服务端以 `GET` 请求访问 `storePageUrl`，请求超时时间为 10 秒。
- **更新规则**：仅当最终响应状态码等于 `200` 时，将 `adStatus` 更新为 `白包在线`；非 200、空链接或请求异常均不修改项目状态。
- **并发保护**：更新时会再次限定当前投放状态仍为 `未上线`，避免覆盖人工修改。

## 2026-06-29 项目小时报表手动同步

- **方法/路径**：`POST /api/v3/admin/{securePath}/projects/aggregate-hourly`
- **控制器**：`ProjectController::aggregateHourly`
- **Request**：`ProjectAggregateHourlyRequest`
- **说明**：同步调用 `project:aggregate-hourly`，按日期、小时和可选项目 ID 重建 `project_report_hourly` 数据。该接口只处理小时报表，不会触发 `project_daily_aggregates` 日报聚合。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| startDate | string | 是 | 开始日期，`YYYY-MM-DD` |
| endDate | string | 是 | 结束日期，必须大于等于 `startDate` |
| hourFrom | int | 否 | 开始小时，0-23；不传时命令默认按场景处理 |
| hourTo | int | 否 | 结束小时，0-23，必须大于等于 `hourFrom` |
| projectId | int | 否 | 项目 ID；传入后只重算该项目 |

### 请求示例

```json
{
  "startDate": "2026-06-29",
  "endDate": "2026-06-29",
  "hourFrom": 9,
  "hourTo": 12,
  "projectId": 12
}
```

### 返回示例

```json
{
  "success": true,
  "startDate": "2026-06-29",
  "endDate": "2026-06-29",
  "hourFrom": 9,
  "hourTo": 12,
  "projectId": 12,
  "exitCode": 0,
  "output": "Start aggregating project hourly data..."
}
```

### 数据来源

- 用户小时数据：`v3_user_report_count`
- 流量小时数据：`traffic_platform_usage_hourly`
- 广告收益小时数据：`ad_revenue_hourly`
- 投放小时数据：暂未接入，`adSpendCost` 固定为 `0.000000`，投放比率字段返回 `null`

## 手动项目聚合接口运行态说明

- `POST /api/v3/admin/{securePath}/projects/aggregate` 和 `POST /api/v3/admin/{securePath}/projects/aggregate-hourly` 会在 HTTP 请求内同步调用 Artisan 命令。
- 为避免 Octane/常驻 Worker 的数据库连接状态影响命令执行，接口在调用命令前后会回滚残留事务、断开并重连默认数据库连接，使运行状态更接近 CLI 手动执行。
- 命令执行成功后会刷新项目报表查询缓存版本，避免项目日报/小时报表 JSON 查询在 60 秒缓存期内继续返回聚合前的旧数据。
- 该调整不改变请求参数、响应结构和聚合命令本身的计算口径。
