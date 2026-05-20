# DNS API 文档

本文档说明 DNS 管理相关接口（V3 Admin），基于本地 DNS 表进行查询与受限写入，并保留外部 DNS 执行动作接口。

---

## 1. Provider 列表

- **方法/路径**：`GET /api/v3/admin/{securePath}/dns/providers`
- **控制器**：`DnsToolController::providers`
- **Request**：`DnsProviderIndexRequest`
- **数据来源**：`dns_provider`

### 1.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| keyword | string | 否 | 关键词（匹配 name/tags/note） |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |

### 1.2 返回字段（data[]）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 主键 |
| name | string | Provider 名称 |
| tags | string | 业务标签 |
| note | string | 备注 |
| officialWebsite | string/null | 官方网站 |
| apiHost | string/null | API Host |
| requestTimeout | int | 请求超时（秒） |
| rateLimitPerMinute | int | 每分钟限流 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

---

## 2. Provider 详情

- **方法/路径**：`GET /api/v3/admin/{securePath}/dns/providers/detail`
- **控制器**：`DnsToolController::providerDetail`
- **Request**：`IdRequest`
- **数据来源**：`dns_provider`

### 2.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | Provider ID |

---

## 3. 新增 Provider

- **方法/路径**：`POST /api/v3/admin/{securePath}/dns/providers/create`
- **控制器**：`DnsToolController::createProvider`
- **Request**：`DnsProviderStoreRequest`
- **数据写入**：`dns_provider`

### 3.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| name | string | 是 | Provider 名称（唯一） |
| tags | string | 否 | 业务标签 |
| note | string | 否 | 备注 |
| officialWebsite | string(url) | 否 | 官方网站 |
| apiHost | string(url) | 否 | API Host |
| requestTimeout | int | 否 | 请求超时（默认 15） |
| rateLimitPerMinute | int | 否 | 每分钟限流（默认 60） |

---

## 4. 更新 Provider

- **方法/路径**：`POST /api/v3/admin/{securePath}/dns/providers/update`
- **控制器**：`DnsToolController::updateProvider`
- **Request**：`DnsProviderUpdateRequest`
- **数据写入**：`dns_provider`

### 4.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | Provider ID |
| name | string | 否 | Provider 名称（唯一） |
| tags | string | 否 | 业务标签 |
| note | string | 否 | 备注 |
| officialWebsite | string(url) | 否 | 官方网站 |
| apiHost | string(url) | 否 | API Host |
| requestTimeout | int | 否 | 请求超时 |
| rateLimitPerMinute | int | 否 | 每分钟限流 |

---

## 5. Provider 账号列表

- **方法/路径**：`GET /api/v3/admin/{securePath}/dns/provider-accounts`
- **控制器**：`DnsToolController::providerAccounts`
- **Request**：`DnsProviderAccountIndexRequest`
- **数据来源**：`dns_provider_accounts`

### 5.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| keyword | string | 否 | 关键词（匹配 providerCode/accountName/tags/note） |
| providerCode | string | 否 | 平台代码 |
| status | string | 否 | `active` / `disabled` |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |

### 5.2 返回字段（data[]）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 主键 |
| providerCode | string | 平台代码 |
| accountName | string | 账号名称 |
| tags | string | 业务标签 |
| note | string | 备注 |
| configJson | object/null | 鉴权与配置 JSON |
| status | string | 账号状态 |
| lastSyncedAt | string/null | 最近同步时间 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

---

## 6. Provider 账号详情

- **方法/路径**：`GET /api/v3/admin/{securePath}/dns/provider-accounts/detail`
- **控制器**：`DnsToolController::providerAccountDetail`
- **Request**：`IdRequest`
- **数据来源**：`dns_provider_accounts`

### 6.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 账号 ID |

---

## 7. 新增 Provider 账号

- **方法/路径**：`POST /api/v3/admin/{securePath}/dns/provider-accounts/create`
- **控制器**：`DnsToolController::createProviderAccount`
- **Request**：`DnsProviderAccountStoreRequest`
- **数据写入**：`dns_provider_accounts`

### 7.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| providerCode | string | 是 | 平台代码 |
| accountName | string | 是 | 账号名称（唯一） |
| tags | string | 否 | 业务标签 |
| note | string | 否 | 备注 |
| configJson | object | 否 | 鉴权与配置 JSON |
| status | string | 否 | `active` / `disabled`（默认 `active`） |
| lastSyncedAt | string(datetime) | 否 | 最近同步时间 |

---

## 8. 更新 Provider 账号

- **方法/路径**：`POST /api/v3/admin/{securePath}/dns/provider-accounts/update`
- **控制器**：`DnsToolController::updateProviderAccount`
- **Request**：`DnsProviderAccountUpdateRequest`
- **数据写入**：`dns_provider_accounts`

### 8.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 账号 ID |
| providerCode | string | 否 | 平台代码 |
| accountName | string | 否 | 账号名称（唯一） |
| tags | string | 否 | 业务标签 |
| note | string | 否 | 备注 |
| configJson | object | 否 | 鉴权与配置 JSON |
| status | string | 否 | `active` / `disabled` |
| lastSyncedAt | string(datetime) | 否 | 最近同步时间 |

---

## 9. 域名列表（只读）

- **方法/路径**：`GET /api/v3/admin/{securePath}/dns/domains`
- **控制器**：`DnsToolController::domains`
- **Request**：`DnsDomainIndexRequest`
- **数据来源**：`dns_domains`

### 9.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| keyword | string | 否 | 关键词（匹配 domainName/tags/note） |
| providerCode | string | 否 | 平台代码 |
| providerAccountId | int | 否 | Provider 账号 ID |
| syncStatus | string | 否 | `active` / `disabled` / `missing` |
| isAvailable | int | 否 | 0 / 1 |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |

### 9.2 返回字段（data[]）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 记录 ID |
| providerAccountId | int | Provider 账号 ID |
| providerCode | string | 平台代码 |
| accountName | string/null | Provider 账号名称 |
| domainName | string | 根域名 |
| tags | string | 业务标签 |
| note | string | 备注 |
| remoteId | string/null | 平台域名 ID |
| syncStatus | string | 同步状态 |
| isAvailable | int | 是否可分配（0/1） |
| bindingIpCount | int | 当前域名 active 绑定 IP 数 |
| lastSyncedAt | string/null | 最近同步时间 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

---

## 10. 同步域名（外部执行）

- **方法/路径**：`GET /api/v3/admin/{securePath}/dns/domains/sync`
- **控制器**：`DnsToolController::syncDomains`
- **Request**：`DnsDomainSyncRequest`
- **执行方式**：调用外部 DNS 服务接口 `GET /api/v1/domains/sync`

### 10.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| providerAccountId | int | 否 | 指定同步单个账号；不传则同步所有 |

---

## 11. 域名元信息更新（仅 note/tags）

- **方法/路径**：`POST /api/v3/admin/{securePath}/dns/domains/update-meta`
- **控制器**：`DnsToolController::updateDomainMeta`
- **Request**：`DnsDomainUpdateMetaRequest`
- **数据写入**：`dns_domains`

### 11.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 域名记录 ID |
| tags | string | 否 | 业务标签 |
| note | string | 否 | 备注 |

---

## 11. 绑定记录列表（只读）

- **方法/路径**：`GET /api/v3/admin/{securePath}/dns/ip-bindings`
- **控制器**：`DnsToolController::ipBindings`
- **Request**：`DnsIpBindingIndexRequest`
- **数据来源**：`dns_ip_bindings`

### 12.1 请求参数

- **方法/路径**：`GET /api/v3/admin/{securePath}/dns/ip-bindings`
- **控制器**：`DnsToolController::ipBindings`
- **Request**：`DnsIpBindingIndexRequest`
- **数据来源**：`dns_ip_bindings`

### 11.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| keyword | string | 否 | 关键词（匹配 fqdn/subdomain/ipv4/tags/note） |
| status | string | 否 | `active` / `released` |
| ipv4 | string(ip) | 否 | 绑定 IP |
| providerAccountId | int | 否 | Provider 账号 ID |
| domainId | int | 否 | 域名 ID |
| page | int | 否 | 默认 1 |
| pageSize | int | 否 | 默认 20，最大 200 |

### 11.2 返回字段（data[]）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 记录 ID |
| providerAccountId | int | Provider 账号 ID |
| domainId | int | 根域名 ID |
| subdomain | string | 子域名前缀（兼容旧字段） |
| recordName | string | 记录名，`@` 表示根记录 |
| fqdn | string | 完整域名 |
| ipv4 | string | IPv4 地址 |
| recordType | string | 记录类型（当前仅 A） |
| ttl | int | TTL 秒 |
| proxied | int | 是否代理（0/1） |
| rawRecord | object/null | 上游原始记录 |
| tags | string | 业务标签 |
| note | string | 备注 |
| remoteKey | string/null | 远端记录唯一键 |
| remoteRecordId | string/null | 平台记录 ID |
| status | string | 绑定状态（active/released） |
| syncedAt | string/null | 最近同步时间 |
| releasedAt | string/null | 释放时间 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

---

## 13. 按 IP 查询绑定记录（本地库）

- **方法/路径**：`GET /api/v3/admin/{securePath}/dns/records/by-ip`
- **控制器**：`DnsToolController::recordsByIp`
- **Request**：`DnsRecordsByIpRequest`
- **数据来源**：`dns_ip_bindings`（本地库）

### 13.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| ipv4 | string(ip) | 是 | 绑定 IP |
| status | string | 否 | `active` / `released`，默认 `active` |

---

## 14. 绑定记录元信息更新（仅 note/tags）

- **方法/路径**：`POST /api/v3/admin/{securePath}/dns/ip-bindings/update-meta`
- **控制器**：`DnsToolController::updateIpBindingMeta`
- **Request**：`DnsIpBindingUpdateMetaRequest`
- **数据写入**：`dns_ip_bindings`

### 14.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| id | int | 是 | 绑定记录 ID |
| tags | string | 否 | 业务标签 |
| note | string | 否 | 备注 |

---

## 15. IP 解析（外部执行）

- **方法/路径**：`POST /api/v3/admin/{securePath}/dns/records/resolve`
- **控制器**：`DnsToolController::resolveRecord`
- **Request**：`DnsRecordResolveRequest`
- **执行方式**：调用外部 DNS 服务接口

### 15.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| ipv4 | string(ip) | 是 | 目标 IP |
| subdomain | string | 是 | 子域名前缀 |
| domain | string | 是 | 根域名 |
| unique | bool | 否 | 是否唯一解析，默认 false |

---

## 16. 解绑记录（外部执行）

- **方法/路径**：`POST /api/v3/admin/{securePath}/dns/records/unbind`
- **控制器**：`DnsToolController::unbindRecord`
- **Request**：`DnsRecordUnbindRequest`
- **执行方式**：调用外部 DNS 服务接口

### 16.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| ipv4 | string(ip) | 是 | 绑定 IP |
| fqdn | string | 是 | 完整域名 |

---

## 通用说明

- 路径中的 `{securePath}` 由 `admin_setting('secure_path', ...)` 动态生成。
- 所有请求/返回参数均使用 **camelCase**。
- 分页接口统一返回 `data` / `total` / `page` / `pageSize`。
- 权限约束：
  - 可新增/更新：`dns_provider`、`dns_provider_accounts`
  - 仅允许更新 `note`、`tags`：`dns_domains`、`dns_ip_bindings`
- `dns_ip_bindings` 当前结构已包含 `recordName/recordType/proxied/rawRecord/remoteKey/syncedAt/releasedAt` 等字段；列表接口会按 camelCase 返回。
