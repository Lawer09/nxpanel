## 一、节点管理（Server Manage）

### 1. 获取所有节点列表

```
GET /api/v2/{secure_path}/server/manage/getNodes
```

**响应字段**（`Server` 模型）：

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int | 节点 ID |
| `name` | string | 节点名称 |
| `type` | string | 协议类型，可选值见下方 |
| `host` | string | 节点地址（域名或 IP） |
| `port` | string/int | 连接端口 |
| `server_port` | int | 服务端口 |
| `group_ids` | array | 权限组 ID 数组 |
| `route_ids` | array | 路由组 ID 数组 |
| `tags` | array | 节点标签 |
| `show` | boolean | 是否显示 |
| `rate` | float | 基础倍率 |
| `rate_time_enable` | boolean | 是否启用动态倍率 |
| `rate_time_ranges` | array | 动态倍率时间段规则 |
| `parent_id` | int/null | 父节点 ID |
| `sort` | int | 排序值 |
| `code` | string/null | 自定义节点 ID |
| `protocol_settings` | object | 协议配置（不同 type 结构不同） |
| `last_check_at` | int/null | 最后检查时间（时间戳） |
| `last_push_at` | int/null | 最后推送时间（时间戳） |
| `online` | int | 在线用户数 |
| `is_online` | int | 是否在线（0/1） |
| `available_status` | int | 可用状态：0=未运行, 1=未使用或异常, 2=正常运行 |
| `load_status` | object/null | 负载状态（CPU、内存、磁盘等） |
| `groups` | array | 权限组详情（`{id, name}`） |
| `parent` | object/null | 父节点信息 |

**支持的协议类型**（`type` 可选值）：

`hysteria`, `vless`, `trojan`, `vmess`, `tuic`, `shadowsocks`, `anytls`, `socks`, `naive`, `http`, `mieru`

---

### 2. 创建/编辑节点

```
POST /api/v2/{secure_path}/server/manage/save
```

**请求参数**（`ServerSave` 验证规则）：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | int | 否 | 传入则为编辑，不传则为创建 |
| `type` | string | 是 | 协议类型 |
| `name` | string | 是 | 节点名称 |
| `host` | string | 是 | 节点地址 |
| `port` | string | 是 | 连接端口 |
| `server_port` | int | 是 | 服务端口 |
| `rate` | numeric | 是 | 基础倍率 |
| `group_ids` | array | 否 | 权限组 ID 数组 |
| `route_ids` | array | 否 | 路由组 ID 数组 |
| `parent_id` | int | 否 | 父节点 ID |
| `code` | string | 否 | 自定义节点 ID |
| `tags` | array | 否 | 节点标签 |
| `excludes` | array | 否 | 排除项 |
| `ips` | array | 否 | IP 列表 |
| `show` | boolean | 否 | 是否显示 |
| `rate_time_enable` | boolean | 否 | 是否启用动态倍率 |
| `rate_time_ranges` | array | 否 | 动态倍率规则 |
| `rate_time_ranges.*.start` | string | 条件必填 | 开始时间 `H:i` |
| `rate_time_ranges.*.end` | string | 条件必填 | 结束时间 `H:i` |
| `rate_time_ranges.*.rate` | numeric | 条件必填 | 倍率乘数 |
| `protocol_settings` | object | 否 | 协议配置（结构因 type 而异） |

**各协议的 `protocol_settings` 字段**：

| 协议 | protocol_settings 字段 |
|------|----------------------|
| **shadowsocks** | `cipher`(必填), `obfs`, `obfs_settings.path`, `obfs_settings.host`, `plugin`, `plugin_opts` |
| **vmess** | `tls`(必填,int), `network`(必填), `network_settings`, `tls_settings.server_name`, `tls_settings.allow_insecure` |
| **trojan** | `network`(必填), `network_settings`, `server_name`, `allow_insecure` |
| **hysteria** | `version`(必填,int), `alpn`, `obfs.open`, `obfs.type`, `obfs.password`, `tls.server_name`, `tls.allow_insecure`, `bandwidth.up`, `bandwidth.down`, `hop_interval` |
| **vless** | `tls`(必填,int), `network`(必填), `network_settings`, `flow`, `tls_settings.*`, `reality_settings.*` |
| **socks** | 无额外字段 |
| **naive** | `tls`(必填,int), `tls_settings` |
| **http** | `tls`(必填,int), `tls_settings` |
| **mieru** | `transport`(必填), `multiplexing`(必填) |
| **anytls** | `tls`, `alpn`, `padding_scheme` |

---

### 3. 更新节点显示状态

```
POST /api/v2/{secure_path}/server/manage/update
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | int | 是 | 节点 ID |
| `show` | int | 否 | 显示状态（0/1） |

---

### 4. 删除节点

```
POST /api/v2/{secure_path}/server/manage/drop
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | int | 是 | 节点 ID | 

---

### 5. 复制节点

```
POST /api/v2/{secure_path}/server/manage/copy
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | int | 是 | 要复制的节点 ID |

复制后新节点 `show=0`（隐藏），`code=null`。

---

### 6. 节点排序

```
POST /api/v2/{secure_path}/server/manage/sort
```

**请求体**：JSON 数组，每个元素包含 `id` 和 `order`：

```json
[
  { "id": 1, "order": 1 },
  { "id": 2, "order": 2 }
]
```

---

## 二、权限组管理（Server Group）

### 1. 获取权限组列表

```
GET /api/v2/{secure_path}/server/group/fetch
```

**响应字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int | 组 ID |
| `name` | string | 组名 |
| `users_count` | int | 用户数量 |
| `server_count` | int | 节点数量 |

### 2. 创建/编辑权限组

```
POST /api/v2/{secure_path}/server/group/save
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | int | 否 | 传入则为编辑 |
| `name` | string | 是 | 组名 |

### 3. 删除权限组

```
POST /api/v2/{secure_path}/server/group/drop
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | int | 是 | 组 ID |

删除前会校验：该组不能被节点、订阅、用户引用。

---

## 三、路由管理（Server Route）

### 1. 获取路由列表

```
GET /api/v2/{secure_path}/server/route/fetch
```

**响应字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int | 路由 ID |
| `remarks` | string | 备注 |
| `match` | array | 匹配规则列表 |
| `action` | string | 动作类型：`block` 或 `dns` |
| `action_value` | string/null | 动作值（如 DNS 地址） |

### 2. 创建/编辑路由

```
POST /api/v2/{secure_path}/server/route/save
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | int | 否 | 传入则为编辑 |
| `remarks` | string | 是 | 备注 |
| `match` | array | 是 | 匹配规则数组 |
| `action` | string | 是 | `block` 或 `dns` |
| `action_value` | string | 否 | 动作值 |

### 3. 删除路由

```
POST /api/v2/{secure_path}/server/route/drop
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | int | 是 | 路由 ID |

---

## 四、路由注册汇总

```
GET  /api/v2/{secure_path}/server/manage/getNodes
POST /api/v2/{secure_path}/server/manage/save
POST /api/v2/{secure_path}/server/manage/update
POST /api/v2/{secure_path}/server/manage/drop
POST /api/v2/{secure_path}/server/manage/copy
POST /api/v2/{secure_path}/server/manage/sort

GET  /api/v2/{secure_path}/server/group/fetch
POST /api/v2/{secure_path}/server/group/save
POST /api/v2/{secure_path}/server/group/drop

GET  /api/v2/{secure_path}/server/route/fetch
POST /api/v2/{secure_path}/server/route/save
POST /api/v2/{secure_path}/server/route/drop
```

## 五、通用说明
- **认证**：所有接口需要在请求头携带 `Authorization: Bearer {token}`
- **响应格式**：成功时返回 `{ "data": ... }`，失败时返回 `{ "message": "...", "errors": ... }`
- **Content-Type**：`application/json`
- `{secure_path}` 从登录接口响应中获取

## 动态倍率字段

### 请求字段

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `rate` | numeric | 是 | 基础倍率，未启用动态倍率或当前时间不匹配任何规则时使用 |
| `rate_time_enable` | boolean | 否 | 是否启用动态倍率，默认 `false` |
| `rate_time_ranges` | array | 否 | 动态倍率规则数组 |
| `rate_time_ranges.*.start` | string | 条件必填 | 开始时间，格式 `HH:mm`，如 `"08:00"` |
| `rate_time_ranges.*.end` | string | 条件必填 | 结束时间，格式 `HH:mm`，如 `"23:59"` |
| `rate_time_ranges.*.rate` | numeric | 条件必填 | 该时间段的倍率，>= 0 |

> 当 `rate_time_ranges` 数组存在时，其中每条规则的 `start`、`end`、`rate` 三个字段均为必填。

### 请求示例

```json
{
  "rate": 1.0,
  "rate_time_enable": true,
  "rate_time_ranges": [
    { "start": "00:00", "end": "08:00", "rate": 0.5 },
    { "start": "08:00", "end": "18:00", "rate": 1.0 },
    { "start": "18:00", "end": "23:59", "rate": 2.0 }
  ]
}
```

---

## VLESS 协议 `protocol_settings` 字段

### `tls` 模式说明

| `tls` 值 | 含义 | 需要填写的配置 |
|-----------|------|---------------|
| `0` | 无加密 | 无 |
| `1` | TLS | `tls_settings` |
| `2` | Reality | `reality_settings` |

### 完整字段列表

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `tls` | int | 是 | 安全模式：0=无, 1=TLS, 2=Reality |
| `network` | string | 是 | 传输协议，如 `tcp`, `ws`, `grpc`, `h2` 等 |
| `network_settings` | object | 否 | 传输协议配置（因 network 类型而异） |
| `flow` | string | 否 | 流控模式，如 `xtls-rprx-vision` |
| `tls_settings.server_name` | string | 否 | SNI 服务器名称指示，用于 TLS 证书验证 |
| `tls_settings.allow_insecure` | boolean | 否 | 是否跳过证书验证 |
| `reality_settings.server_name` | string | 否 | 伪装站点域名（dest） |
| `reality_settings.server_port` | integer | 否 | 伪装站点端口 |
| `reality_settings.allow_insecure` | boolean | 否 | 是否允许不安全连接 |
| `reality_settings.public_key` | string | 否 | Reality 公钥 |
| `reality_settings.private_key` | string | 否 | Reality 私钥 |
| `reality_settings.short_id` | string | 否 | Short ID，十六进制，偶数长度，最长 16 位 | [8-cite-1](#8-cite-1) 

### 请求示例 — TLS 模式

```json
{
  "protocol_settings": {
    "tls": 1,
    "network": "ws",
    "network_settings": {
      "path": "/ws",
      "headers": { "Host": "example.com" }
    },
    "flow": "",
    "tls_settings": {
      "server_name": "example.com",
      "allow_insecure": false
    }
  }
}
```

### 请求示例 — Reality 模式

```json
{
  "protocol_settings": {
    "tls": 2,
    "network": "tcp",
    "flow": "xtls-rprx-vision",
    "reality_settings": {
      "server_name": "www.microsoft.com",
      "server_port": 443,
      "allow_insecure": false,
      "public_key": "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
      "private_key": "yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy",
      "short_id": "abcd1234"
    }
  }
}
```

### 前端表单联动逻辑

- 当 `tls = 0` 时，隐藏 `tls_settings` 和 `reality_settings`
- 当 `tls = 1` 时，显示 `tls_settings`，隐藏 `reality_settings`
- 当 `tls = 2` 时，显示 `reality_settings`，隐藏 `tls_settings`



# 🌐 IP 池管理 API 接口文档

## 📌 基础信息

- **基础路径**: `/api/v2/{secure_path}/ip-pool`
- **认证方式**: Bearer Token (Admin 权限)
- **请求头**: `Content-Type: application/json`
- **响应格式**: JSON

---

## 📋 响应格式说明

### 成功响应 (2xx)

```json
{
  "code": 0,
  "msg": "success",
  "data": { /* 实际数据 */ }
}
```

### 失败响应 (4xx/5xx)

```json
{
  "code": 1,
  "msg": "错误信息",
  "data": null
}
```

---

## 🔌 API 端点详解

---

### 1️⃣ **获取 IP 池列表** 
**POST/GET** `/api/v2/{secure_path}/ip-pool/fetch`

获取 IP 池列表，支持分页、过滤和排序。

#### 请求参数

| 参数 | 类型 | 必需 | 说明 | 示例 |
|------|------|------|------|------|
| `current` | int | ✅ | 当前页码 | `1` |
| `pageSize` | int | ✅ | 每页数量 | `10` |
| `search_ip` | string | ❌ | IP 搜索 | `92.246.139` |
| `country` | string | ❌ | 国家代码过滤 | `DE` |
| `status` | string | ❌ | 状态过滤 | `active` 或 `cooldown` |
| `risk_level` | string | ❌ | 风险等级过滤 | `high`/`medium`/`low` |
| `min_success_rate` | int | ❌ | 最小成功率 | `80` |
| `sort_by` | string | ❌ | 排序字段 | `created_at`/`score`/`load` |
| `sort_order` | string | ❌ | 排序顺序 | `asc`/`desc` |

#### 请求示例

```bash
curl -X GET "http://api.example.com/api/v2/{secure_path}/ip-pool/fetch?current=1&pageSize=10&country=DE&status=active" \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json"
```

#### 响应示例 ✅

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "data": [
      {
        "id": 1,
        "ip": "92.246.139.197",
        "hostname": "stereovpn9.ptr.network",
        "city": "Frankfurt am Main",
        "region": "Hesse",
        "country": "DE",
        "loc": "50.1155,8.6842",
        "org": "AS210644 AEZA GROUP LLC",
        "postal": "60306",
        "timezone": "Europe/Berlin",
        "readme_url": "https://ipinfo.io/missingauth",
        "score": 95,
        "load": 45,
        "max_load": 100,
        "success_rate": 98,
        "status": "active",
        "risk_level": 5,
        "total_requests": 1250,
        "successful_requests": 1225,
        "last_used_at": 1711497600,
        "created_at": 1711411200,
        "updated_at": 1711497600
      }
    ],
    "total": 50,
    "pageSize": 10,
    "page": 1
  }
}
```

#### 错误示例 ❌

```json
{
  "code": 1,
  "msg": "查询",
  "data": null
}
```

---

### 2️⃣ **获取 IP 详情**
**POST** `/api/v2/{secure_path}/ip-pool/detail`

获取单个 IP 的详细信息。

#### 请求参数

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| `id` | int | ✅ | IP 池 ID |

#### 请求示例

```bash
curl -X POST "http://api.example.com/api/v2/{secure_path}/ip-pool/detail" \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 1
  }'
```

#### 响应示例 ✅

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "id": 1,
    "ip": "92.246.139.197",
    "hostname": "stereovpn9.ptr.network",
    "city": "Frankfurt am Main",
    "region": "Hesse",
    "country": "DE",
    "loc": "50.1155,8.6842",
    "org": "AS210644 AEZA GROUP LLC",
    "postal": "60306",
    "timezone": "Europe/Berlin",
    "readme_url": "https://ipinfo.io/missingauth",
    "score": 95,
    "load": 45,
    "max_load": 100,
    "success_rate": 98,
    "status": "active",
    "risk_level": 5,
    "total_requests": 1250,
    "successful_requests": 1225,
    "last_used_at": 1711497600,
    "created_at": 1711411200,
    "updated_at": 1711497600
  }
}
```

#### 错误示例 ❌

```json
{
  "code": 1,
  "msg": "IP 池不存在",
  "data": null
}
```

---

### 3️⃣ **添加/编辑 IP**
**POST** `/api/v2/{secure_path}/ip-pool/save`

新增或编辑 IP 到池中。

#### 请求参数

| 参数 | 类型 | 必需 | 说明 | 示例 |
|------|------|------|------|------|
| `id` | int | ❌ | IP 池 ID（编辑时必需） | `1` |
| `ip` | string | ✅ | IP 地址（新增时必需，编辑时不可修改） | `92.246.139.197` |
| `hostname` | string | ❌ | 主机名 | `stereovpn9.ptr.network` |
| `city` | string | ❌ | 城市 | `Frankfurt am Main` |
| `region` | string | ❌ | 地区/州 | `Hesse` |
| `country` | string | ❌ | 国家代码 | `DE` |
| `loc` | string | ❌ | 坐标 (lat,long) | `50.1155,8.6842` |
| `org` | string | ❌ | 组织/ISP | `AS210644 AEZA GROUP LLC` |
| `postal` | string | ❌ | 邮编 | `60306` |
| `timezone` | string | ❌ | 时区 | `Europe/Berlin` |
| `readme_url` | string | ❌ | 信息链接 | `https://ipinfo.io/missingauth` |
| `score` | int | ❌ | 评分 (0-100) | `100` |
| `max_load` | int | ❌ | 最大负载 | `100` |
| `status` | string | ❌ | 状态 | `active` 或 `cooldown` |
| `risk_level` | int | ❌ | 风险值 (0-100) | `0` |

#### 请求示例 - 新增

```bash
curl -X POST "http://api.example.com/api/v2/{secure_path}/ip-pool/save" \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{
    "ip": "92.246.139.197",
    "hostname": "stereovpn9.ptr.network",
    "city": "Frankfurt am Main",
    "region": "Hesse",
    "country": "DE",
    "loc": "50.1155,8.6842",
    "org": "AS210644 AEZA GROUP LLC",
    "postal": "60306",
    "timezone": "Europe/Berlin",
    "readme_url": "https://ipinfo.io/missingauth",
    "score": 100,
    "max_load": 100,
    "status": "active",
    "risk_level": 0
  }'
```

#### 请求示例 - 编辑

```bash
curl -X POST "http://api.example.com/api/v2/{secure_path}/ip-pool/save" \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 1,
    "score": 85,
    "risk_level": 10,
    "status": "active"
  }'
```

#### 响应示例 ✅

```json
{
  "code": 0,
  "msg": "success",
  "data": true
}
```

#### 错误示例 ❌

```json
{
  "code": 1,
  "msg": "该 IP 已存在",
  "data": null
}
```

或

```json
{
  "code": 1,
  "msg": "IP 地址格式错误",
  "data": null
}
```

或

```json
{
  "code": 1,
  "msg": "IP 不存在",
  "data": null
}
```

---

### 4️⃣ **删除 IP**
**POST** `/api/v2/{secure_path}/ip-pool/delete`

删除一个或多个 IP。

#### 请求参数

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| `ids` | array | ✅ | IP 池 ID 数组 |

#### 请求示例

```bash
curl -X POST "http://api.example.com/api/v2/{secure_path}/ip-pool/delete" \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{
    "ids": [1, 2, 3]
  }'
```

#### 响应示例 ✅

```json
{
  "code": 0,
  "msg": "success",
  "data": true
}
```

#### 错误示例 ❌

```json
{
  "code": 1,
  "msg": "ID 不能为空",
  "data": null
}
```

---

### 5️⃣ **启用 IP**
**POST** `/api/v2/{secure_path}/ip-pool/enable`

将 IP 状态改为 `active`（活跃）。

#### 请求参数

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| `id` | int | ✅ | IP 池 ID |

#### 请求示例

```bash
curl -X POST "http://api.example.com/api/v2/{secure_path}/ip-pool/enable" \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 1
  }'
```

#### 响应示例 ✅

```json
{
  "code": 0,
  "msg": "success",
  "data": true
}
```

#### 错误示例 ❌

```json
{
  "code": 1,
  "msg": "IP 不存在",
  "data": null
}
```

---

### 6️⃣ **禁用 IP**
**POST** `/api/v2/{secure_path}/ip-pool/disable`

将 IP 状态改为 `cooldown`（冷却/禁用）。

#### 请求参数

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| `id` | int | ✅ | IP 池 ID |

#### 请求示例

```bash
curl -X POST "http://api.example.com/api/v2/{secure_path}/ip-pool/disable" \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 1
  }'
```

#### 响应示例 ✅

```json
{
  "code": 0,
  "msg": "success",
  "data": true
}
```

---

### 7️⃣ **重置 IP 评分**
**POST** `/api/v2/{secure_path}/ip-pool/reset-score`

手动更新 IP 的评分。

#### 请求参数

| 参数 | 类型 | 必需 | 说明 | 范围 |
|------|------|------|------|------|
| `id` | int | ✅ | IP 池 ID | - |
| `score` | int | ✅ | 新评分 | 0-100 |

#### 请求示例

```bash
curl -X POST "http://api.example.com/api/v2/{secure_path}/ip-pool/reset-score" \
  -H "Authorization: Bearer your_token" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 1,
    "score": 85
  }'
```

#### 响应示例 ✅

```json
{
  "code": 0,
  "msg": "success",
  "data": true
}
```

#### 错误示例 ❌

```json
{
  "code": 1,
  "msg": "评分必须在 0-100 之间",
  "data": null
}
```

---

### 8️⃣ **获取统计数据**
**GET** `/api/v2/{secure_path}/ip-pool/stats`

获取 IP 池的统计数据。

#### 请求参数

无

#### 请求示例

```bash
curl -X GET "http://api.example.com/api/v2/{secure_path}/ip-pool/stats" \
  -H "Authorization: Bearer your_token"
```

#### 响应示例 ✅

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "total": 150,
    "active": 145,
    "cooldown": 5,
    "avg_score": 88,
    "avg_success_rate": 96,
    "high_risk_count": 3,
    "by_country": [
      {
        "country": "DE",
        "count": 45
      },
      {
        "country": "US",
        "count": 38
      },
      {
        "country": "FR",
        "count": 32
      },
      {
        "country": "NL",
        "count": 28
      },
      {
        "country": "GB",
        "count": 7
      }
    ]
  }
}
```

---

## 📊 数据字段说明

### IP 池对象结构

```json
{
  "id": 1,
  "ip": "92.246.139.197",                    // IP 地址
  "hostname": "stereovpn9.ptr.network",      // 主机名
  "city": "Frankfurt am Main",               // 城市
  "region": "Hesse",                         // 地区/州
  "country": "DE",                           // 国家代码 (ISO 3166-1 alpha-2)
  "loc": "50.1155,8.6842",                   // 坐标 (纬度,经度)
  "org": "AS210644 AEZA GROUP LLC",          // 组织/ISP 信息
  "postal": "60306",                         // 邮编
  "timezone": "Europe/Berlin",               // 时区
  "readme_url": "https://ipinfo.io/missingauth", // 信息链接
  "score": 95,                               // 评分 (0-100)
  "load": 45,                                // 当前负载
  "max_load": 100,                           // 最大负载
  "success_rate": 98,                        // 成功率 (0-100)%
  "status": "active",                        // 状态: active/cooldown
  "risk_level": 5,                           // 风险值 (0-100)
  "total_requests": 1250,                    // 总请求数
  "successful_requests": 1225,               // 成功请求数
  "last_used_at": 1711497600,                // 最后使用时间 (Unix 时间戳)
  "created_at": 1711411200,                  // 创建时间 (Unix 时间戳)
  "updated_at": 1711497600                   // 更新时间 (Unix 时间戳)
}
```

### 状态说明

| 状态 | 说明 |
|------|------|
| `active` | 活跃/可用状态，可以正常使用 |
| `cooldown` | 冷却/禁用状态，暂时不可用 |

### 风险等级分类

| 等级 | 范围 | 说明 |
|------|------|------|
| 低 | 0-29 | 风险低，可安全使用 |
| 中 | 30-69 | 风险中等，需要监控 |
| 高 | 70-100 | 风险高，不建议使用 |

---

## 🔐 认证与权限

所有 API 端点都需要以下认证：

### 请求头

```
Authorization: Bearer {access_token}
```

### 权限要求

- 需要具有 **Admin** 角色
- 需要具有 IP 池管理权限

### 示例

```bash
curl -H "Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..." \
     http://api.example.com/api/v2/{secure_path}/ip-pool/fetch
```

---

## ⚠️ 错误码列表

| 错误码 | HTTP 状态 | 说明 |
|--------|----------|------|
| 0 | 200 | 操作成功 |
| 1 | 400/500 | 通用错误 |
| 400202 | 400 | 数据不存在 |
| 400201 | 400 | 操作冲突（如 IP 已存在） |
| 422 | 422 | 请求参数验证失败 |
| 500 | 500 | 服务器内部错误 |

---

## 获取 IP 详细信息

**地址**: `GET /api/v2/{source_tag}/ip-pool/get-ipinfo`

**参数**:

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| ip | string | ✅ | IP 地址 |

**返回值**:

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "ip": "92.246.139.197",
    "hostname": "stereovpn9.ptr.network",
    "city": "Frankfurt am Main",
    "region": "Hesse",
    "country": "DE",
    "loc": "50.1155,8.6842",
    "org": "AS210644 AEZA GROUP LLC",
    "postal": "60306",
    "timezone": "Europe/Berlin",
    "readme": "https://ipinfo.io/missingauth"
  }
}
```

## 📖 API 接口说明

**地址**: `/api/client/performance/report`  
**方法**: `POST`  
**认证**: User Token

**参数**:

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| node_id | int | ✅ | 节点 ID |
| delay | int | ✅ | 延迟(ms)，0-60000 |
| success_rate | int | ✅ | 连接成功率(%)，0-100 |
| app_version | string | ❌ | APP 版本 |
| metadata | json | ❌ | 其他数据 |

**返回值**:

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "id": 1,
    "message": "上报成功"
  }
}
```

**地址**: `/api/client/performance/batchReport`  
**方法**: `POST`  
**认证**: User Token

**参数**:

```json
{
  "reports": [
    {
      "node_id": 1,
      "delay": 45,
      "success_rate": 98,
      "app_version": "1.0.0"
    },
    {
      "node_id": 2,
      "delay": 52,
      "success_rate": 95
    }
  ]
}
```

**返回值**:

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "count": 2,
    "message": "批量上报成功"
  }
}
```

**地址**: `/api/client/performance/history`  
**方法**: `GET`  
**认证**: User Token

**参数**:

| 参数 | 类型 | 说明 |
|------|------|------|
| limit | int | 限制数量(默认100) |
| node_id | int | 节点 ID(可选) |

**返回值**: 返回数组列表

---

**地址**: `/api/client/performance/nodeStats`  
**方法**: `GET`  
**认证**: User Token

**参数**:

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| node_id | int | ✅ | 节点 ID |
| days | int | ❌ | 统计天数(1-90，默认7) |

**返回值**:

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "node_id": 1,
    "period_days": 7,
    "avg_delay": 48.5,
    "min_delay": 35,
    "max_delay": 120,
    "avg_success_rate": 96.5,
    "report_count": 156
  }
}
```

### ASN 接口

#### 获取ASN列表
**地址**: `GET/POST /api/v2/{secure_path}/asn/fetch`

| 参数 | 类型 | 说明 |
|------|------|------|
| current | int | 页码 |
| pageSize | int | 每页数量 |
| search | string | 搜索ASN或名称 |
| country | string | 国家代码 |
| type | string | 类型(ISP/CDN/企业) |
| is_datacenter | boolean | 是否数据中心 |
| min_reliability | int | 最小可靠性 |

**返回**: 分页数据列表

---

#### 获取ASN详情
**地址**: `POST /api/v2/{secure_path}/asn/detail`

| 参数 | 类型 | 必需 |
|------|------|------|
| id | int | ✅ |

---

#### 添加/编辑ASN
**地址**: `POST /api/v2/{secure_path}/asn/save`

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| id | int | ❌ | 编辑时必需 |
| asn | string | ✅ | ASN号 (新增必需) |
| name | string | ✅ | ASN名称 |
| description | string | ❌ | 描述 |
| country | string | ❌ | 国家代码 |
| type | string | ❌ | 类型 |
| is_datacenter | boolean | ❌ | 是否数据中心 |
| reliability | int | ❌ | 可靠性(0-100) |
| reputation | int | ❌ | 声誉(0-100) |
| metadata | json | ❌ | 其他信息 |

---

#### 删除ASN
**地址**: `POST /api/v2/{secure_path}/asn/delete`

| 参数 | 类型 | 说明 |
|------|------|------|
| ids | array | ASN ID数组 |

---

#### 获取ASN统计
**地址**: `GET /api/v2/{secure_path}/asn/stats`

**返回**: 统计数据

---

### Provider 接口

#### 获取Provider列表
**地址**: `GET/POST /api/v2/{secure_path}/provider/fetch`

| 参数 | 类型 | 说明 |
|------|------|------|
| current | int | 页码 |
| pageSize | int | 每页数量 |
| search | string | 搜索名称或ASN |
| country | string | 国家代码 |
| type | string | 类型 |
| is_active | boolean | 是否活跃 |
| min_reliability | int | 最小可靠性 |
| asn_id | int | ASN ID |

**返回**: 分页数据列表

---

#### 获取Provider详情
**地址**: `POST /api/v2/{secure_path}/provider/detail`

| 参数 | 类型 | 必需 |
|------|------|------|
| id | int | ✅ |

---

#### 添加/编辑Provider
**地址**: `POST /api/v2/{secure_path}/provider/save`

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| id | int | ❌ | 编辑时必需 |
| name | string | ✅ | 提供商名称(新增必需) |
| description | string | ❌ | 描述 |
| website | string | ❌ | 官网 |
| email | string | ❌ | 邮箱 |
| phone | string | ❌ | 电话 |
| country | string | ❌ | 国家代码 |
| type | string | ❌ | 类型 |
| asn_id | int | ❌ | ASN ID |
| asn | string | ❌ | ASN号 |
| reliability | int | ❌ | 可靠性(0-100) |
| reputation | int | ❌ | 声誉(0-100) |
| speed_level | int | ❌ | 速度等级(0-100) |
| stability | int | ❌ | 稳定性(0-100) |
| is_active | boolean | ❌ | 是否活跃 |
| regions | json | ❌ | 覆盖地区 |
| services | json | ❌ | 提供的服务 |
| metadata | json | ❌ | 其他信息 |

---

#### 删除Provider
**地址**: `POST /api/v2/{secure_path}/provider/delete`

| 参数 | 类型 | 说明 |
|------|------|------|
| ids | array | Provider ID数组 |

---

#### 批量更新Provider状态
**地址**: `POST /api/v2/{secure_path}/provider/updateStatus`

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| ids | array | ✅ | Provider ID数组 |
| is_active | boolean | ✅ | 是否活跃 |

---

#### 获取Provider统计
**地址**: `GET /api/v2/{secure_path}/provider/stats`

**返回**: 统计数据

---

### ASN 接口新增

#### 获取 ASN 关联的 Provider 列表
**地址**: `GET /api/v2/admin/asn/getProviders`

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| asn_id | int | ✅ | ASN ID |
| current | int | ❌ | 页码 |
| pageSize | int | ❌ | 每页数量 |

**返回**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "asn_id": 1,
    "asn": "AS210644",
    "data": [
      {
        "id": 1,
        "name": "Provider 1",
        "reliability": 95
      }
    ],
    "total": 10,
    "page": 1
  }
}
```

---

#### 批量关联 Provider 到 ASN
**地址**: `POST /api/v2/admin/asn/bindProviders`

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| asn_id | int | ✅ | ASN ID |
| provider_ids | array | ✅ | Provider ID 数组 |

**请求**:
```json
{
  "asn_id": 1,
  "provider_ids": [1, 2, 3]
}
```

**返回**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "message": "关联成功",
    "count": 3
  }
}
```

---

#### 批量解除 Provider 与 ASN 的关联
**地址**: `POST /api/v2/admin/asn/unbindProviders`

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| asn_id | int | ✅ | ASN ID |
| provider_ids | array | ✅ | Provider ID 数组 |

**请求**:
```json
{
  "asn_id": 1,
  "provider_ids": [1, 2, 3]
}
```

**返回**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "message": "解除关联成功",
    "count": 3
  }
}
```

---

### Provider 接口新增

#### 批量更新 Provider 的 ASN 关联
**地址**: `POST /api/v2/admin/provider/updateAsn`

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| provider_ids | array | ✅ | Provider ID 数组 |
| asn_id | int | ✅ | 要关联的 ASN ID |

**请求**:
```json
{
  "provider_ids": [1, 2, 3],
  "asn_id": 1
}
```

**返回**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "message": "更新成功",
    "count": 3
  }
}
```

---

#### 获取无关联 ASN 的 Provider
**地址**: `GET /api/v2/admin/provider/getUnboundProviders`

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| current | int | ❌ | 页码 |
| pageSize | int | ❌ | 每页数量 |
| search | string | ❌ | 搜索名称 |

**返回**: 未绑定 ASN 的 Provider 列表

---

#### 获取某个 ASN 下的所有 Provider
**地址**: `GET /api/v2/admin/provider/getByAsn`

| 参数 | 类型 | 必需 | 说明 |
|------|------|------|------|
| asn_id | int | ✅ | ASN ID |
| current | int | ❌ | 页码 |
| pageSize | int | ❌ | 每页数量 |

**返回**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "asn_id": 1,
    "asn_name": "Alibaba CDN",
    "data": [
      {
        "id": 1,
        "name": "Alibaba Cloud",
        "reliability": 95
      }
    ],
    "total": 5,
    "page": 1
  }
}
```


## 机器管理 API 接口说明

基础路径：`/api/v2/{admin_source}/machine`

---

### 1. 获取机器列表

**`GET/POST /machine/fetch`**

**Query 参数：**

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `page` | int | 否 | 页码，默认 `1` |
| `pageSize` | int | 否 | 每页条数，默认 `10` |
| `name` | string | 否 | 机器名称（模糊搜索） |
| `status` | string | 否 | 状态筛选：`online` / `offline` / `error` |
| `hostname` | string | 否 | 主机名（模糊搜索） |
| `tags` | string | 否 | 标签（模糊搜索） |

**成功响应：**
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "data": [{ "id": 1, "name": "...", ... }],
    "total": 100,
    "pageSize": 10,
    "page": 1
  }
}
```

> 注意：响应中 `password` 和 `private_key` 字段会被隐藏。

---

### 2. 创建机器

**`POST /machine/save`**

**Body 参数：**

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `name` | string | **是** | 机器名称，最大255字符 |
| `hostname` | string | **是** | 主机名，唯一，最大255字符 |
| `ip_address` | string | **是** | IP地址 |
| `port` | int | **是** | 端口号，范围 1-65535 |
| `username` | string | **是** | SSH用户名 |
| `password` | string | 否* | SSH密码（加密存储） |
| `private_key` | string | 否* | SSH私钥（加密存储） |
| `os_type` | string | 否 | 操作系统类型 |
| `cpu_cores` | string | 否 | CPU核心数 |
| `memory` | string | 否 | 内存大小 |
| `disk` | string | 否 | 磁盘大小 |
| `gpu_info` | string | 否 | GPU信息 |
| `bandwidth` | int | 否 | 带宽 (Mbps) |
| `provider` | int | 否 | 供应商ID |
| `price` | decimal | 否 | 价格 (8,2) |
| `pay_mode` | int | 否 | 付费模式 |
| `tags` | string | 否 | 标签 |
| `description` | string | 否 | 描述 |
| `is_active` | boolean | 否 | 是否激活，默认 `true` |

> \* `password` 和 `private_key` 至少需要填一个。

**成功响应：**
```json
{
  "code": 0,
  "msg": "机器创建成功",
  "data": { "id": 1, "name": "...", "status": "offline", ... }
}
```

---

### 3. 更新机器

**`POST /machine/update`**

**Body 参数：**

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `id` | int | **是** | 机器ID |
| 其余字段 | - | 否 | 同 `save` 接口，所有字段均为可选（`sometimes`） |

**成功响应：**
```json
{
  "code": 0,
  "msg": "机器更新成功",
  "data": { "id": 1, "name": "...", ... }
}
```

---

### 4. 获取机器详情

**`POST /machine/detail`**

**Body 参数：**

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `id` | int | **是** | 机器ID |

**成功响应：**
```json
{
  "code": 0,
  "msg": "success",
  "data": { "id": 1, "name": "...", ... }
}
```

---

### 5. 删除机器

**`POST /machine/drop`**

**Body 参数：**

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `id` | int | **是** | 机器ID |

**成功响应：**
```json
{ "code": 0, "msg": "机器删除成功" }
```

> 使用软删除（SoftDeletes），数据不会真正从数据库移除。

---

### 6. 批量删除

**`POST /machine/batchDrop`** 

**Body 参数：**

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `ids` | array | **是** | 机器ID数组，如 `[1, 2, 3]` |

**成功响应：**
```json
{ "code": 0, "msg": "批量删除成功" }
```

---

### 7. 测试连接

**`POST /machine/testConnection`**

**Body 参数：**

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `id` | int | **是** | 机器ID |

**成功响应：**
```json
{
  "code": 0,
  "msg": "连接测试成功",
  "data": { "status": "online" }
}
```

> 当前此接口为**桩实现**，直接返回 `online`，尚未接入真实 SSH 连接逻辑。

---

### 统一错误响应格式

所有接口错误时返回：

```json
{ "code": 1, "msg": "错误信息" }
```

验证失败时额外返回 `errors` 字段：

```json
{ "code": 1, "msg": "数据验证失败", "errors": { "name": ["..."] } }
```