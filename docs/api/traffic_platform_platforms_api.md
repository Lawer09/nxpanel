# 流量平台统计接口文档

## 1. 基础信息

- 接口前缀：`/api/v3/admin/{securePath}/traffic-platform`
- 管理端鉴权：需管理员登录态
- 统一返回：

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {}
}
```

---

## 2. 平台配置接口

### 2.1 平台列表

`GET /platforms`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| enabled | int | 否 | 0/1 |
| keyword | string | 否 | 按 code/name 模糊搜索 |
| page | int | 否 | 默认1 |
| pageSize | int | 否 | 默认20，最大200 |

返回结构：

```json
{
  "page": 1,
  "pageSize": 20,
  "total": 1,
  "data": [
    {
      "id": 1,
      "code": "kkoip",
      "name": "KKOIP",
      "baseUrl": "https://www.kkoip.com",
      "enabled": 1,
      "createdAt": "2026-05-18 10:00:00",
      "updatedAt": "2026-05-18 10:00:00"
    }
  ]
}
```

返回字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| page | int | 当前页 |
| pageSize | int | 每页条数 |
| total | int | 总条数 |
| data | array | 平台列表 |

data[] 字段说明：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 平台 ID |
| code | string | 平台编码（唯一） |
| name | string | 平台名称 |
| baseUrl | string | 平台基础地址 |
| enabled | int | 启用状态，1 启用 / 0 禁用 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

### 2.2 新增平台

`POST /platforms/create`

Body：

```json
{
  "code": "kkoip",
  "name": "KKOIP",
  "baseUrl": "https://www.kkoip.com",
  "enabled": 1
}
```

返回字段（data）：与「2.1 平台列表」的 `data[]` 单项字段一致。

### 2.3 修改平台

`POST /platforms/update`

Body（可选字段，必须包含 id）：

```json
{
  "id": 1,
  "name": "KKOIP",
  "baseUrl": "https://www.kkoip.com",
  "enabled": 1
}
```

返回字段（data）：与「2.1 平台列表」的 `data[]` 单项字段一致。

### 2.4 启用/禁用平台

`POST /platforms/update-status`

Body：

```json
{
  "id": 1,
  "enabled": 1
}
```

返回字段（data）：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| data | bool | 操作结果，`true` 表示更新成功 |

---

## 3. 平台账号接口

### 3.1 账号列表

`GET /accounts`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| enabled | int | 否 | 0/1 |
| keyword | string | 否 | 按账号名/外部账号ID检索 |
| page | int | 否 | 默认1 |
| pageSize | int | 否 | 默认20，最大200 |

返回结构：

```json
{
  "page": 1,
  "pageSize": 20,
  "total": 1,
  "data": [
    {
      "id": 1,
      "platformId": 1,
      "platformCode": "kkoip",
      "platformName": "KKOIP",
      "accountName": "kkoip-main",
      "externalAccountId": "3494058",
      "balance": 10240,
      "credentialMasked": {
        "accessid": "349***058",
        "secret": "******"
      },
      "timezone": "Asia/Shanghai",
      "enabled": 1,
      "createdAt": "2026-05-18 10:00:00",
      "updatedAt": "2026-05-18 10:00:00"
    }
  ]
}
```

返回字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| page | int | 当前页 |
| pageSize | int | 每页条数 |
| total | int | 总条数 |
| data | array | 账号列表 |

data[] 字段说明：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 账号 ID |
| platformId | int | 平台 ID |
| platformCode | string | 平台编码 |
| platformName | string | 平台名称 |
| accountName | string | 账号名称 |
| externalAccountId | string | 外部平台账号 ID |
| balance | int | 账号剩余可用流量（MB） |
| credentialMasked | object | 脱敏后的凭证信息 |
| timezone | string | 账号时区 |
| enabled | int | 启用状态，1 启用 / 0 禁用 |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

### 3.2 账号详情

`GET /accounts/detail?id=1`

返回字段（data）：与「3.1 账号列表」的 `data[]` 单项字段一致。

### 3.3 新增账号

`POST /accounts/create`

Body：

```json
{
  "platformCode": "kkoip",
  "accountName": "kkoip-main",
  "externalAccountId": "3494058",
  "balance": 10240,
  "credential": {
    "accessid": "3494058",
    "secret": "******"
  },
  "timezone": "Asia/Shanghai",
  "enabled": 1
}
```

返回字段（data）：与「3.1 账号列表」的 `data[]` 单项字段一致。

### 3.4 修改账号

`POST /accounts/update`

Body（可选字段，必须包含 id）：

```json
{
  "id": 1,
  "accountName": "kkoip-main",
  "externalAccountId": "3494058",
  "balance": 10240,
  "credential": {
    "secret": "******"
  },
  "timezone": "Asia/Shanghai",
  "enabled": 1
}
```

返回字段（data）：与「3.1 账号列表」的 `data[]` 单项字段一致。

### 3.5 启用/禁用账号

`POST /accounts/update-status`

Body：

```json
{
  "id": 1,
  "enabled": 1
}
```

返回字段（data）：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| data | bool | 操作结果，`true` 表示更新成功 |

### 3.6 测试账号连接

`POST /accounts/test`

Body：

```json
{
  "id": 1
}
```

说明：用于测试账号连通与实时接口返回，不直接代表统计报表已入库。

返回字段说明：该接口透传内部 Go 服务返回的 `data`，字段会因平台实现不同而变化。

---

## 4. 流量查询接口

### 4.1 小时流量明细

`GET /traffic-platform/usages/hourly`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| accountId | int | 否 | 平台账号ID |
| externalUid | string | 否 | 子账号ID；空维度请传空字符串 `""` |
| startTime | string | 否 | 开始时间（基于 `stat_time` 过滤） |
| endTime | string | 否 | 结束时间（基于 `stat_time` 过滤） |
| geo | string | 否 | 地区；空维度请传空字符串 `""` |
| page | int | 否 | 默认1 |
| pageSize | int | 否 | 默认50，最大200 |

返回字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| page | int | 当前页 |
| pageSize | int | 每页条数 |
| total | int | 总条数 |
| data | array | 小时流量明细 |

data[] 字段说明：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| platformAccountId | int | 平台账号 ID |
| platformCode | string | 平台编码 |
| balance | int | 账号剩余可用流量（MB） |
| externalUid | string | 子账号 ID（空维度时为空字符串） |
| externalUsername | string/null | 子账号名称 |
| statTime | string | 统计时间 |
| statDate | string | 统计日期 |
| statHour | int | 统计小时 |
| statMinute | int | 统计分钟 |
| geo | string | 地区编码（空维度时为空字符串） |
| region | string | 地区名称（空维度时为空字符串） |
| trafficBytes | int | 流量字节数 |
| trafficMb | float | 流量 MB |
| accountName | string | 平台账号名称 |

### 4.2 日流量汇总

`GET /traffic-platform/usages/daily`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| accountId | int | 否 | 平台账号ID |
| externalUid | string | 否 | 子账号ID；空维度请传空字符串 `""` |
| startDate | string | 否 | 开始日期 `YYYY-MM-DD` |
| endDate | string | 否 | 结束日期 `YYYY-MM-DD` |
| geo | string | 否 | 地区；空维度请传空字符串 `""` |
| page | int | 否 | 默认1 |
| pageSize | int | 否 | 默认50，最大200 |

返回字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| page | int | 当前页 |
| pageSize | int | 每页条数 |
| total | int | 总条数 |
| data | array | 日流量汇总 |

data[] 字段说明：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| statDate | string | 统计日期 |
| platformAccountId | int | 平台账号 ID |
| platformCode | string | 平台编码 |
| externalUid | string | 子账号 ID（空维度时为空字符串） |
| externalUsername | string/null | 子账号名称 |
| geo | string | 地区编码（空维度时为空字符串） |
| region | string | 地区名称（空维度时为空字符串） |
| trafficBytes | int | 流量字节数（日累计） |
| trafficMb | float | 流量 MB（日累计） |
| trafficGb | float | 兼容字段，`trafficMb / 1024` |
| accountName | string | 平台账号名称 |

### 4.3 月流量汇总

`GET /traffic-platform/usages/monthly`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| accountId | int | 否 | 平台账号ID |
| externalUid | string | 否 | 子账号ID；空维度请传空字符串 `""` |
| startMonth | string | 否 | 开始月份 `YYYY-MM` |
| endMonth | string | 否 | 结束月份 `YYYY-MM` |
| page | int | 否 | 默认1 |
| pageSize | int | 否 | 默认50，最大200 |

返回字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| page | int | 当前页 |
| pageSize | int | 每页条数 |
| total | int | 总条数 |
| data | array | 月流量汇总 |

data[] 字段说明：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| statMonth | string | 统计月份，格式 `YYYY-MM` |
| platformAccountId | int | 平台账号 ID |
| platformCode | string | 平台编码 |
| externalUid | string | 子账号 ID（空维度时为空字符串） |
| externalUsername | string/null | 子账号名称 |
| trafficBytes | int | 流量字节数（月累计） |
| trafficMb | float | 流量 MB（月累计） |
| trafficGb | float | 兼容字段，`trafficMb / 1024` |
| accountName | string | 平台账号名称 |

### 4.4 流量趋势

`GET /traffic-platform/usages/trend`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| accountId | int | 否 | 平台账号ID |
| externalUid | string | 否 | 子账号ID；空维度请传空字符串 `""` |
| startDate | string | 否 | 开始日期 |
| endDate | string | 否 | 结束日期 |
| dimension | string | 否 | `hour` / `day` / `month`，默认 `day` |

返回字段（data[]）：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| time | string | 时间点（随 `dimension` 变化） |
| trafficMb | float | 该时间点流量 MB |
| trafficGb | float | 兼容字段，`trafficMb / 1024` |

### 4.5 流量排行

`GET /traffic-platform/usages/ranking`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| startDate | string | 否 | 开始日期 |
| endDate | string | 否 | 结束日期 |
| rankBy | string | 否 | `account` / `external_uid` / `geo`，默认 `account` |
| limit | int | 否 | 默认20，最大100 |

返回字段（data[]，按 `rankBy` 不同）：

| rankBy | 字段 | 类型 | 说明 |
| --- | --- | --- | --- |
| account | platformAccountId | int | 平台账号 ID |
| account | platformCode | string | 平台编码 |
| account | trafficMb | float | 流量 MB |
| account | trafficGb | float | 兼容字段，`trafficMb / 1024` |
| account | accountName | string | 平台账号名称 |
| external_uid | platformAccountId | int | 平台账号 ID |
| external_uid | platformCode | string | 平台编码 |
| external_uid | externalUid | string | 子账号 ID |
| external_uid | externalUsername | string/null | 子账号名称 |
| external_uid | trafficMb | float | 流量 MB |
| external_uid | trafficGb | float | 兼容字段，`trafficMb / 1024` |
| external_uid | accountName | string | 平台账号名称 |
| geo | geo | string | 地区编码 |
| geo | region | string | 地区名称 |
| geo | trafficMb | float | 流量 MB |
| geo | trafficGb | float | 兼容字段，`trafficMb / 1024` |

---

## 5. 同步接口

### 5.1 手动触发同步

`POST /sync`

Body：

```json
{
  "accountId": 1,
  "startDate": "2026-04-27",
  "endDate": "2026-04-29"
}
```

可选字段：

```json
{
  "platformCode": "kkoip"
}
```

说明：

- `platformCode` 可不传，后端会根据 `accountId` 自动补齐
- 若传 `platformCode`，会做一致性校验，不匹配返回 `422`

返回字段说明：该接口透传内部 Go 服务返回的 `data`，通常包含任务标识、状态、时间范围等信息，具体以节点服务实现为准。

### 5.2 同步任务列表

`GET /sync-jobs`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| accountId | int | 否 | 平台账号ID |
| status | string | 否 | `running` / `success` / `failed` |
| startTime | string | 否 | 创建时间起 |
| endTime | string | 否 | 创建时间止 |
| page | int | 否 | 默认1 |
| pageSize | int | 否 | 默认20，最大200 |

返回字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| page | int | 当前页 |
| pageSize | int | 每页条数 |
| total | int | 总条数 |
| data | array | 同步任务列表 |

data[] 字段说明：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | 同步任务 ID |
| platformAccountId | int | 平台账号 ID |
| platformCode | string | 平台编码 |
| status | string | 任务状态（如 `running` / `success` / `failed`） |
| startDate | string | 同步开始日期 |
| endDate | string | 同步结束日期 |
| startedAt | string/null | 任务开始时间 |
| finishedAt | string/null | 任务结束时间 |
| errorMessage | string/null | 失败原因 |
| accountName | string | 平台账号名称（后端补充） |
| createdAt | string | 创建时间 |
| updatedAt | string | 更新时间 |

### 5.3 同步任务详情

`GET /sync-jobs/detail?id=1`

返回字段（data）：与「5.2 同步任务列表」的 `data[]` 单项字段一致。
