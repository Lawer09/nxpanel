# User API 文档

## 用户列表查询

`POST /api/v3/admin/user/fetch`

支持 GET/POST。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | `string` | 否 | 按 ID 查询，逗号分隔多个，例如 `"1,2,3"` |
| `current` | `int` | 否 | 页码，默认 `1` |
| `pageSize` | `int` | 否 | 每页条数，默认 `10` |
| `meta` | `object` | 否 | 按 `register_metadata` JSON 字段筛选，key 为元数据字段名 |
| `filter` | `array` | 否 | 通用字段筛选，格式 `[{id, value}]` |
| `sort` | `array` | 否 | 排序，格式 `[{id, desc: bool}]` |
| `onlyBanned` | `bool` | 否 | 只查询已封禁用户 |
| `createdAtFrom` | `string\|int` | 否 | 注册时间起始，包含边界；支持 Unix 时间戳、`YYYY-MM-DD`、`YYYY-MM-DD HH:mm:ss` |
| `createdAtTo` | `string\|int` | 否 | 注册时间结束，包含边界；支持 Unix 时间戳、`YYYY-MM-DD`、`YYYY-MM-DD HH:mm:ss`，仅日期格式会按当天 `23:59:59` 处理 |

### 示例

```json
POST /api/v3/admin/user/fetch
{
    "meta": {
        "app_id": "com.example.app",
        "channel": "telegram"
    },
    "current": 1,
    "pageSize": 20,
    "createdAtFrom": "2026-06-10",
    "createdAtTo": "2026-06-12",
    "filter": [
        {"id": "email", "value": "test"}
    ],
    "sort": [
        {"id": "created_at", "desc": true}
    ]
}
```

按 ID 查询（不走分页）：

```json
POST /api/v3/admin/user/fetch
{
    "id": "1,2,3"
}
```

### 返回

```json
{
    "data": [
        {
            "id": 1,
            "email": "user@example.com",
            "register_metadata": {
                "app_id": "com.example.app",
                "channel": "telegram"
            },
            "plan_id": 1,
            "plan": {"id": 1, "name": "Pro"},
            "invite_user": null,
            "group": null,
            "balance": 0,
            "commission_balance": 0,
            "subscribe_url": "...",
            "transfer_enable": 1073741824,
            "u": 0,
            "d": 0,
            "total_used": 0,
            "expired_at": 1767225600,
            "banned": false,
            "report_traffic": 0.0
        }
    ],
    "total": 1,
    "page": 1,
    "pageSize": 20
}
```

---

## 更新用户

`POST /api/v3/admin/user/update`

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | `int` | 是 | 用户 ID |
| `email` | `string` | 否 | 邮箱 |
| `password` | `string` | 否 | 密码，最少 8 位 |
| `transfer_enable` | `numeric` | 否 | 总流量 (KB) |
| `expired_at` | `int` | 否 | 过期时间戳 |
| `banned` | `bool` | 否 | 是否封禁 |
| `plan_id` | `int` | 否 | 订阅计划 ID，变更时自动更新 `group_id` |
| `commission_rate` | `int` | 否 | 返佣比例 0-100 |
| `discount` | `int` | 否 | 折扣比例 0-100 |
| `is_admin` | `bool` | 否 | 是否管理员 |
| `is_staff` | `bool` | 否 | 是否员工 |
| `u` | `int` | 否 | 上行流量 |
| `d` | `int` | 否 | 下行流量 |
| `balance` | `numeric` | 否 | 余额 |
| `commission_type` | `int` | 否 | 返佣类型 |
| `commission_balance` | `numeric` | 否 | 佣金余额 |
| `remarks` | `string` | 否 | 备注 |
| `speed_limit` | `int` | 否 | 限速 Mbps |
| `device_limit` | `int` | 否 | 设备数量限制 |
| `register_metadata` | `object` | 否 | 注册元数据，支持 `{"app_id": "..."}` 格式 |
| `user_type` | `string` | 否 | 用户类型，最大 32 字符；未传时保持原值 |
| `menus` | `array` | 否 | 用户菜单数组；传空数组可清空菜单 |
| `invite_user_email` | `string` | 否 | 邀请人邮箱 |

### 示例

```json
POST /api/v3/admin/user/update
{
    "id": 1,
    "email": "new@example.com",
    "plan_id": 2,
    "expired_at": 1767225600,
    "register_metadata": {
        "app_id": "com.example.app",
        "channel": "telegram"
    }
}
```

### 返回

```json
{
    "data": true
}
```

```json
// 失败
{
    "code": 400202,
    "message": "用户不存在"
}
```

---

## 添加用户

`POST /api/v3/admin/user/generate`

单个添加和批量生成用户均支持 `user_type` 与 `menus`。

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `user_type` | `string` | 否 | 用户类型，最大 32 字符；未传时使用数据库默认值 `global` |
| `menus` | `array` | 否 | 用户菜单数组；未传时为空 |

示例：

```json
POST /api/v3/admin/user/generate
{
    "email_prefix": "demo",
    "email_suffix": "example.com",
    "password": "password123",
    "plan_id": 1,
    "user_type": "custom",
    "menus": ["dashboard", "reports"]
}
```

---

## 批量封禁用户并记录注册 IP

`POST /api/v3/admin/user/batchBan`

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `user_ids` | `int[]` | 是 | 需要封禁的用户 ID 列表 |
| `reason` | `string` | 否 | 封禁原因，最长 500 字符 |

接口会将用户 `banned` 更新为 `1`，清理用户登录会话，并从 `register_metadata.ip` 提取注册 IP 写入 `blocked_user_ips`。没有合法注册 IP 的用户仍会被封禁，但会出现在 `skippedIpUserIds`。

### 请求示例

```json
POST /api/v3/admin/user/batchBan
{
    "user_ids": [1001, 1002],
    "reason": "fraud batch"
}
```

### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": {
        "bannedUserCount": 2,
        "blockedIpCount": 1,
        "blockedIps": [
            "203.0.113.30"
        ],
        "skippedIpUserIds": [
            1002
        ]
    }
}
```

### AID 注册 IP 封禁规则

`POST /api/v1/passport/auth/loginByAid` 与 `POST /api/v3/passport/auth/loginByAid` 支持在 `metadata.ip` 中传客户端 IP。

当 AID 自动注册新用户时：

- `metadata.ip` 会保存到 `v2_user.register_metadata.ip`
- 如果该 IP 已存在于 `blocked_user_ips.ip`，新用户会立即被标记为 `banned=1`
- V1 `loginByAid` 被封禁的新用户不会返回登录凭证，接口返回账号已封禁错误
- V3 `loginByAid` 被封禁的新用户仍返回登录凭证，并通过 `data.is_ban=true` 告知前端当前用户已封禁

请求示例：

```json
POST /api/v3/passport/auth/loginByAid
{
    "aid": "device-001",
    "metadata": {
        "app_id": "com.example.app",
        "ip": "203.0.113.30"
    }
}
```

成功返回会额外包含 `is_ban` 字段：

| 字段 | 类型 | 说明 |
|------|------|------|
| `is_ban` | `bool` | 当前用户是否封禁；V3 `loginByAid` 即使命中封禁也会成功返回，此时该字段为 `true` |

---

## AID 自定义封禁检测规则

用于在 `loginByAid` 自动创建新用户后，根据后台配置的规则自动封禁用户，并记录该用户注册 IP，避免同类注册继续放量。

规则名称 `name` 用于后台识别规则；`timezone` 为必填项，用于判断所有时间条件；检测条件 `cutoffAt`、`weeklyWindows`、`dateWindows`、`packageNames`、`projectCodes`、`countries` 都不是必填项。未配置的检测条件视为不限制，但最终 `packageNames` 为空的规则不会参与封禁检测。

### 触发范围

- 仅影响 `POST /api/v1/passport/auth/loginByAid` 和 `POST /api/v3/passport/auth/loginByAid` 自动创建的新用户。
- 已存在用户登录不会触发这套自定义规则。
- 命中规则后，系统会将新用户设置为 `banned = 1`，清理登录会话，并从 `register_metadata.ip` 写入 `blocked_user_ips`。
- 如果未传合法 IP，用户仍会被封禁，但不会新增 IP 封禁记录。
- V1 `loginByAid` 命中后返回现有封禁错误，不返回登录凭证。
- V3 `loginByAid` 命中后仍返回登录凭证，并通过 `data.is_ban=true` 告知前端当前用户已封禁。

### 匹配规则

- 包名字段优先级：`metadata.package_name` > `metadata.packageName` > `metadata.app_id`。
- 国家字段取 `metadata.country`，保存和匹配时统一转为大写，例如 `us` 会转为 `US`。
- `timezone` 为必填时区，例如 `Asia/Shanghai`、`America/New_York`；`cutoffAt`、`weeklyWindows`、`dateWindows` 均按该时区解释和判断。
- `cutoffAt` 为空表示不限制截止时间；非空时当前时间必须小于等于规则的 `cutoffAt`。
- `weeklyWindows` 为空表示不限制星期和小时段；非空时当前星期和当前 `HH:mm` 必须落在任一 `weeklyWindows` 时间段内。
- 每个 `weeklyWindows` 同时包含星期和小时段。
- `weekday` 使用 ISO 定义：`1=周一`，`7=周日`，`start/end` 使用 `HH:mm` 小时段。
- `dateWindows` 为空表示不限制特定日期时间段；非空时当前日期和当前 `HH:mm` 必须落在任一 `dateWindows` 时间段内。
- 每个 `dateWindows` 同时包含日期和小时段，格式为 `{"date":"2026-06-23","start":"09:00","end":"18:00"}`。
- 时间段不支持跨天，跨天场景需要拆成两段，例如周一 `23:00-23:59` 和周二 `00:00-02:00`。
- `packageNames` 为空且没有通过 `projectCodes` 扩展出包名时，该规则不会参与封禁检测；非空时当前包名必须包含在 `packageNames` 数组中。
- `projectCodes` 为空表示不通过项目代号扩展包名；非空时保存规则会按 `project_user_app_map.project_code` 查询启用映射，并将对应 `app_id` 合并到最终 `packageNames`。
- `countries` 为空表示不限制国家；非空时当前国家必须包含在 `countries` 数组中。
- 当 `packageNames` 和 `countries` 都非空时，必须同时满足包名和国家包含条件。
- `packageNames` / `countries` 是封禁匹配列表；匹配到列表且满足其他条件后会执行封禁。
- 命中任一启用规则即封禁，不继续匹配后续规则。

### 查询规则列表

`POST /api/v3/{secure_path}/user/aidLoginBanRule/fetch`

支持 GET/POST。

#### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `enabled` | `bool` | 否 | 按启用状态筛选 |
| `packageName` | `string` | 否 | 按包名精确筛选 |
| `country` | `string` | 否 | 按国家精确筛选，服务端会转大写 |
| `current` | `int` | 否 | 页码，默认 `1` |
| `pageSize` | `int` | 否 | 每页条数，默认 `10`，最大 `200` |

#### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": {
        "data": [
            {
                "id": 1,
                "name": "US night fraud block",
                "enabled": true,
                "timezone": "Asia/Shanghai",
                "cutoffAt": "2026-06-30 23:59:59",
                "weeklyWindows": [
                    {"weekday": 1, "start": "00:00", "end": "06:00"}
                ],
                "dateWindows": [
                    {"date": "2026-06-23", "start": "09:00", "end": "18:00"}
                ],
                "packageNames": ["com.example.vpn"],
                "projectCodes": ["rocket"],
                "countries": ["US"],
                "reason": "aid login custom rule",
                "createdBy": {"id": 1, "email": "admin@example.com"},
                "updatedBy": {"id": 1, "email": "admin@example.com"},
                "createdAt": 1782144000,
                "updatedAt": 1782144000
            }
        ],
        "total": 1,
        "page": 1,
        "pageSize": 10
    }
}
```

### 新增规则

`POST /api/v3/{secure_path}/user/aidLoginBanRule/save`

#### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | `string` | 是 | 规则名称，最大 191 字符 |
| `enabled` | `bool` | 否 | 是否启用，默认启用 |
| `timezone` | `string` | 是 | 规则时区，例如 `Asia/Shanghai`；所有时间条件均按该时区解释 |
| `cutoffAt` | `string|null` | 否 | 规则有效截止时间，例如 `2026-06-30 23:59:59`；空表示不限制 |
| `weeklyWindows` | `array` | 否 | 一周内生效时间段，空数组或不传表示不限制 |
| `weeklyWindows[].weekday` | `int` | 是 | 传 `weeklyWindows` 时必填；星期，`1=周一`，`7=周日` |
| `weeklyWindows[].start` | `string` | 是 | 传 `weeklyWindows` 时必填；开始时间，格式 `HH:mm` |
| `weeklyWindows[].end` | `string` | 是 | 传 `weeklyWindows` 时必填；结束时间，格式 `HH:mm`，必须大于 `start` |
| `dateWindows` | `array` | 否 | 特定日期生效时间段，空数组或不传表示不限制 |
| `dateWindows[].date` | `string` | 是 | 传 `dateWindows` 时必填；日期，格式 `Y-m-d` |
| `dateWindows[].start` | `string` | 是 | 传 `dateWindows` 时必填；开始时间，格式 `HH:mm` |
| `dateWindows[].end` | `string` | 是 | 传 `dateWindows` 时必填；结束时间，格式 `HH:mm`，必须大于 `start` |
| `packageNames` | `string[]` | 否 | 封禁匹配包名列表；最终列表为空时规则不会参与封禁检测 |
| `projectCodes` | `string[]` | 否 | 项目代号列表；保存时会查询 `project_user_app_map` 中 `enabled=1` 的相同 `project_code`，并将对应 `app_id` 合并到最终 `packageNames` |
| `countries` | `string[]` | 否 | 封禁匹配国家列表，空数组或不传表示不限制 |
| `reason` | `string` | 否 | 封禁原因，最大 500 字符 |

#### 请求示例

```json
{
    "name": "US night fraud block",
    "enabled": true,
    "timezone": "Asia/Shanghai",
    "cutoffAt": "2026-06-30 23:59:59",
    "weeklyWindows": [
        {"weekday": 1, "start": "00:00", "end": "06:00"},
        {"weekday": 2, "start": "00:00", "end": "06:00"}
    ],
    "dateWindows": [
        {"date": "2026-06-23", "start": "09:00", "end": "18:00"}
    ],
    "packageNames": ["com.example.vpn"],
    "projectCodes": ["rocket"],
    "countries": ["US"],
    "reason": "aid login custom rule"
}
```

### 更新规则

`POST /api/v3/{secure_path}/user/aidLoginBanRule/update`

#### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | `int` | 是 | 规则 ID |
| `name` | `string` | 否 | 规则名称 |
| `enabled` | `bool` | 否 | 是否启用 |
| `timezone` | `string` | 否 | 规则时区，例如 `Asia/Shanghai` |
| `cutoffAt` | `string|null` | 否 | 规则有效截止时间；传 `null` 可清空限制 |
| `weeklyWindows` | `array|null` | 否 | 一周内生效时间段，格式同新增接口；传 `null` 或空数组可清空限制 |
| `dateWindows` | `array|null` | 否 | 特定日期生效时间段，格式同新增接口；传 `null` 或空数组可清空限制 |
| `packageNames` | `string[]` | 否 | 封禁匹配包名列表；最终列表为空时规则不会参与封禁检测 |
| `projectCodes` | `string[]` | 否 | 项目代号列表；传入后会重新保存项目代号，并把启用映射中的 `app_id` 合并到最终 `packageNames` |
| `countries` | `string[]` | 否 | 封禁匹配国家列表 |
| `reason` | `string` | 否 | 封禁原因 |

### 删除规则

`POST /api/v3/{secure_path}/user/aidLoginBanRule/delete`

#### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | `int` | 是 | 规则 ID |

#### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": true
}
```

## 封禁用户 IP 列表查询

`POST /api/v3/admin/user/blockedIp/fetch`

支持 GET/POST。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `ip` | `string` | 否 | 按封禁 IP 精确查询 |
| `bannedUserId` | `int` | 否 | 按被封禁用户 ID 查询 |
| `operatorUserId` | `int` | 否 | 按操作管理员 ID 查询 |
| `current` | `int` | 否 | 页码，默认 `1` |
| `pageSize` | `int` | 否 | 每页条数，默认 `10`，最大 `200` |

### 请求示例

```json
POST /api/v3/admin/user/blockedIp/fetch
{
    "ip": "203.0.113.30",
    "current": 1,
    "pageSize": 20
}
```

### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": {
        "data": [
            {
                "id": 1,
                "ip": "203.0.113.30",
                "reason": "fraud batch",
                "metadata": {
                    "source": "admin_batch_ban",
                    "user_email": "with-ip@example.com"
                },
                "banned_user_id": 1001,
                "operator_user_id": 9001,
                "banned_user": {
                    "id": 1001,
                    "email": "with-ip@example.com"
                },
                "operator_user": {
                    "id": 9001,
                    "email": "admin@example.com"
                },
                "created_at": 1781400000,
                "updated_at": 1781400000
            }
        ],
        "total": 1,
        "page": 1,
        "pageSize": 20
    }
}
```

## 删除封禁用户 IP 记录

`POST /api/v3/admin/user/blockedIp/delete`

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `id` | `int` | 是 | 封禁 IP 记录 ID |

### 请求示例

```json
POST /api/v3/admin/user/blockedIp/delete
{
    "id": 1
}
```

### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": true
}
```

---


## 批量删除封禁用户 IP 记录

`POST /api/v3/admin/user/blockedIp/batchDelete`

该接口只删除 `blocked_user_ips` 中的 IP 封禁记录，用于停止后续 AID 注册时按该 IP 自动封禁；不会自动解除已有用户的 `banned` 状态。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `ids` | `int[]` | 是 | 需要删除的封禁 IP 记录 ID 列表；服务端会去重，空数组会返回校验错误 |

### 请求示例

```json
POST /api/v3/admin/user/blockedIp/batchDelete
{
    "ids": [1, 2, 3]
}
```

### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": {
        "deletedCount": 2,
        "requestedCount": 3,
        "missingIds": [3]
    }
}
```

---

## 邀请相关接口（V1）

> 兼容说明：以下 V1 邀请接口仍可用，但建议迁移到下方 V3 接口。

> 前缀：`/api/v1/user`
> 需要用户登录态（Authorization）

### 生成邀请码

`GET /api/v1/user/invite/save`

#### 说明

- 为当前登录用户生成一个新的邀请码。
- 当该用户未使用的邀请码数量达到系统上限（`invite_gen_limit`，默认 5）时，返回失败。

#### 返回示例

```json
{
    "data": true
}
```

#### 失败示例

```json
{
    "code": 400,
    "message": "The maximum number of creations has been reached"
}
```

### 邀请统计

`GET /api/v1/user/invite/fetch`

#### 说明

返回当前用户的邀请码列表和邀请统计信息。

#### 返回字段

| 字段 | 类型 | 说明 |
|------|------|------|
| `codes` | `array` | 当前用户未使用的邀请码列表 |
| `stat` | `array` | 统计数组，固定顺序如下 |
| `stat[0]` | `int` | 已注册邀请用户数（`invite_user_id = 当前用户`） |
| `stat[1]` | `int` | 累计已获得佣金 |
| `stat[2]` | `number` | 待确认佣金（订单已支付但未结算） |
| `stat[3]` | `int` | 当前佣金比例（%） |
| `stat[4]` | `int` | 当前可用佣金余额 |

#### 返回示例

```json
{
    "data": {
        "codes": [
            {
                "id": 101,
                "code": "AB12CD34",
                "status": 0
            }
        ],
        "stat": [12, 5600, 800, 10, 4200]
    }
}
```

### 返佣明细

`GET /api/v1/user/invite/details`

#### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `current` | `int` | 否 | 页码，默认 `1` |
| `page_size` | `int` | 否 | 每页条数，最小按 `10` 处理 |

#### 说明

- 查询当前用户作为邀请人的返佣记录明细（仅 `get_amount > 0`）。
- 按 `created_at DESC` 排序。

#### 返回示例

```json
{
    "data": [
        {
            "id": 9001,
            "invite_user_id": 1,
            "get_amount": 500,
            "created_at": 1716000000
        }
    ],
    "total": 1
}
```

---

## 邀请相关接口（V3）

> 前缀：`/api/v3/user`
> 需要用户登录态（Authorization）

### 生成邀请码

`POST /api/v3/user/invite-codes/create`

#### 说明

- 为当前登录用户生成一个新的邀请码。
- 当该用户未使用的邀请码数量达到系统上限（`invite_gen_limit`，默认 5）时，返回失败。

#### 返回示例

```json
{
    "data": {
        "created": true,
        "code": "AB12CD34"
    }
}
```

### 使用邀请码（注册后补填）

`POST /api/v3/user/invite-codes/use`

#### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `inviteCode` | `string` | 是 | 邀请码 |

#### 说明

- 用于用户注册时忘记填写邀请码的补录场景。
- 仅允许当前登录用户绑定一次邀请关系。
- 不允许使用自己的邀请码。

#### 返回示例

```json
{
    "data": {
        "bound": true,
        "inviterUserId": 123
    }
}
```

#### 失败示例

```json
{
    "code": 422,
    "message": "Invite user already bound"
}
```

### 邀请统计

`GET /api/v3/user/invite/summary`

#### 说明

返回当前用户的邀请码列表和汇总统计（对象结构）。

#### 返回字段

| 字段 | 类型 | 说明 |
|------|------|------|
| `codes` | `array` | 当前用户未使用的邀请码列表 |
| `summary.invitedUsers` | `int` | 已注册邀请用户数 |
| `summary.totalCommission` | `int` | 累计已获得佣金 |
| `summary.pendingCommission` | `int` | 待确认佣金（订单已支付但未结算） |
| `summary.commissionRate` | `int` | 当前佣金比例（%） |
| `summary.availableCommission` | `int` | 当前可用佣金余额 |

#### 返回示例

```json
{
    "data": {
        "codes": [
            {
                "id": 101,
                "code": "AB12CD34",
                "status": 0
            }
        ],
        "summary": {
            "invitedUsers": 12,
            "totalCommission": 5600,
            "pendingCommission": 800,
            "commissionRate": 10,
            "availableCommission": 4200
        }
    }
}
```

### 返佣明细分页

`GET /api/v3/user/invite/commissions`

#### 请求参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `page` | `int` | 否 | 页码，默认 `1` |
| `pageSize` | `int` | 否 | 每页条数，默认 `10`，最大 `200` |

#### 说明

- 查询当前用户作为邀请人的返佣记录明细（仅 `get_amount > 0`）。
- 按 `created_at DESC` 排序。
- 返回统一分页结构：`data`、`total`、`page`、`pageSize`。

#### 返回示例

```json
{
    "data": {
        "data": [
            {
                "id": 9001,
                "invite_user_id": 1,
                "get_amount": 500,
                "created_at": 1716000000
            }
        ],
        "total": 1,
        "page": 1,
        "pageSize": 10
    }
}
```
## 普通登录返回 user_type 与 menus

`POST /api/v1/passport/auth/login`、`POST /api/v2/passport/auth/login` 与 `POST /api/v3/passport/auth/login` 普通邮箱密码登录成功后，`data` 会返回 `user_type` 与 `menus` 字段。

| 字段 | 类型 | 说明 |
|------|------|------|
| `user_type` | `string` | 用户类型，默认值为 `global`；仅普通登录返回，注册、AID 登录、邮件链接/Token 登录不额外返回该字段。 |
| `menus` | `array` | 用户菜单数组，未配置时返回空数组 `[]`；仅普通登录返回。 |

示例：

```json
{
    "data": {
        "token": "subscribe-token",
        "auth_data": "Bearer xxxxxx",
        "is_admin": false,
        "user_type": "global",
        "menus": []
    }
}
```
