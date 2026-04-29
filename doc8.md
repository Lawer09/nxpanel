# 流量代理相关接口（V3）

## 1. 基础信息

- 接口前缀：`/v3/`
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

`GET /traffic-platform/platforms`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| enabled | int | 否 | 0/1 |
| keyword | string | 否 | 按 code/name 模糊搜索 |

### 2.2 新增平台

`POST /traffic-platform/platforms`

Body：

```json
{
  "code": "kkoip",
  "name": "KKOIP",
  "baseUrl": "https://www.kkoip.com",
  "enabled": 1
}
```

### 2.3 修改平台

`PUT /traffic-platform/platforms/{id}`

Body（可选字段）：

```json
{
  "name": "KKOIP",
  "baseUrl": "https://www.kkoip.com",
  "enabled": 1
}
```

### 2.4 启用/禁用平台

`PATCH /traffic-platform/platforms/{id}/status`

Body：

```json
{
  "enabled": 1
}
```

---

## 3. 平台账号接口

### 3.1 账号列表

`GET /traffic-platform/accounts`

Query 参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| platformCode | string | 否 | 平台编码 |
| enabled | int | 否 | 0/1 |
| keyword | string | 否 | 按账号名/外部账号ID检索 |
| page | int | 否 | 默认1 |
| pageSize | int | 否 | 默认20，最大200 |

### 3.2 账号详情

`GET /traffic-platform/accounts/{id}`

### 3.3 新增账号

`POST /traffic-platform/accounts`

Body：

```json
{
  "platformCode": "kkoip",
  "accountName": "kkoip-main",
  "externalAccountId": "3494058",
  "credential": {
    "accessid": "3494058",
    "secret": "******"
  },
  "timezone": "Asia/Shanghai",
  "enabled": 1
}
```

### 3.4 修改账号

`PUT /traffic-platform/accounts/{id}`

Body（可选字段）：

```json
{
  "accountName": "kkoip-main",
  "externalAccountId": "3494058",
  "credential": {
    "secret": "******"
  },
  "timezone": "Asia/Shanghai",
  "enabled": 1
}
```

### 3.5 启用/禁用账号

`PATCH /traffic-platform/accounts/{id}/status`

Body：

```json
{
  "enabled": 1
}
```

### 3.6 测试账号连接

`POST /traffic-platform/accounts/{id}/test`

说明：用于测试账号连通与实时接口返回，不直接代表统计报表已入库。

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

返回字段（data 每项）包含：

- `platformAccountId`
- `platformCode`
- `externalUid`
- `externalUsername`
- `statTime`
- `statDate`
- `statHour`
- `statMinute`
- `geo`
- `region`
- `trafficBytes`
- `trafficMb`
- `accountName`

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

返回字段（data 每项）包含：

- `statDate`
- `platformAccountId`
- `platformCode`
- `externalUid`
- `externalUsername`
- `geo`
- `region`
- `trafficBytes`
- `trafficMb`
- `trafficGb`（兼容字段，`trafficMb / 1024`）
- `accountName`

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

返回字段（data 每项）包含：

- `statMonth`
- `platformAccountId`
- `platformCode`
- `externalUid`
- `externalUsername`
- `trafficBytes`
- `trafficMb`
- `trafficGb`（兼容字段）
- `accountName`

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

返回字段（data 每项）包含：

- `time`
- `trafficMb`
- `trafficGb`（兼容字段）

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

返回字段（按 rankBy 不同）包含：

- `account` 维度：`platformAccountId`, `platformCode`, `trafficMb`, `trafficGb`, `accountName`
- `external_uid` 维度：`platformAccountId`, `platformCode`, `externalUid`, `externalUsername`, `trafficMb`, `trafficGb`, `accountName`
- `geo` 维度：`geo`, `region`, `trafficMb`, `trafficGb`

---

## 5. 同步接口

### 5.1 手动触发同步

`POST /traffic-platform/sync`

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

### 5.2 同步任务列表

`GET /traffic-platform/sync-jobs`

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

### 5.3 同步任务详情

`GET /traffic-platform/sync-jobs/{id}`
