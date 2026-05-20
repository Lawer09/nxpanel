# DNS 管理接口（V3 Admin）

本文档对应 `DnsToolController`（`/api/v3/admin/{securePath}/dns/*`）。

实现原则：
- 本地库查询：`dns_provider`、`dns_provider_accounts`、`dns_domains`、`dns_ip_bindings`
- 外部调用：仅 `records/resolve`、`records/unbind`
- 字段写入约束：
  - `dns_provider`、`dns_provider_accounts` 允许新增/更新
  - `dns_domains`、`dns_ip_bindings` 仅允许更新 `note`、`tags`

## 1) Provider

### GET `/dns/providers`
查询 Provider 列表。

Query:
- `keyword` string 可选
- `page` int 可选，默认 1
- `pageSize` int 可选，默认 20

Response:
- `page` `pageSize` `total`
- `data[]`: `id,name,tags,note,officialWebsite,apiHost,requestTimeout,rateLimitPerMinute,createdAt,updatedAt`

### GET `/dns/providers/detail?id={id}`
查询 Provider 详情。

### POST `/dns/providers/create`
新增 Provider。

Body:
```json
{
  "name": "godaddy",
  "tags": "prod,global",
  "note": "main provider",
  "officialWebsite": "https://www.godaddy.com",
  "apiHost": "https://api.godaddy.com",
  "requestTimeout": 15,
  "rateLimitPerMinute": 60
}
```

### POST `/dns/providers/update`
更新 Provider（包含 `id`，其余字段按需提交）。

## 2) Provider Accounts

### GET `/dns/provider-accounts`
查询账号列表。

Query:
- `keyword` string 可选
- `providerCode` string 可选
- `status` enum(`active`,`disabled`) 可选
- `page` `pageSize`

### GET `/dns/provider-accounts/detail?id={id}`
查询账号详情。

### POST `/dns/provider-accounts/create`
新增账号。

Body:
```json
{
  "providerCode": "godaddy",
  "accountName": "gd-main",
  "tags": "prod",
  "note": "primary",
  "configJson": {
    "api_key": "***",
    "api_secret": "***"
  },
  "status": "active"
}
```

### POST `/dns/provider-accounts/update`
更新账号（包含 `id`，其余字段按需提交）。

## 3) Domains（只读 + 元信息）

### GET `/dns/domains`
查询域名列表。

Query:
- `keyword` string 可选（匹配 `domain_name/tags/note`）
- `providerCode` string 可选
- `providerAccountId` int 可选
- `syncStatus` enum(`active`,`disabled`,`missing`) 可选
- `isAvailable` int 可选（0/1）
- `page` `pageSize`

### POST `/dns/domains/update-meta`
仅更新 `dns_domains.note`、`dns_domains.tags`。

Body:
```json
{
  "id": 1001,
  "tags": "vip",
  "note": "reserved for A cluster"
}
```

## 4) IP Bindings（只读 + 元信息）

### GET `/dns/ip-bindings`
查询绑定记录列表。

Query:
- `keyword` string 可选（匹配 `fqdn/subdomain/ipv4/tags/note`）
- `status` enum(`active`,`released`) 可选
- `ipv4` ip 可选
- `providerAccountId` int 可选
- `domainId` int 可选
- `page` `pageSize`

### GET `/dns/records/by-ip?ipv4={ipv4}&status=active`
按 IP 查询绑定记录（**本地库查询**）。

### POST `/dns/ip-bindings/update-meta`
仅更新 `dns_ip_bindings.note`、`dns_ip_bindings.tags`。

Body:
```json
{
  "id": 2001,
  "tags": "manual",
  "note": "temporary test"
}
```

## 5) 外部执行接口

### POST `/dns/records/resolve`
调用外部 DNS 服务执行解析。

Body:
```json
{
  "ipv4": "1.2.3.4",
  "subdomain": "node-a",
  "domain": "example.com",
  "unique": true
}
```

### POST `/dns/records/unbind`
调用外部 DNS 服务解绑记录。

Body:
```json
{
  "ipv4": "1.2.3.4",
  "fqdn": "node-a.example.com"
}
```
