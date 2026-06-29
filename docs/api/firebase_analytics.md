# Firebase Analytics API

## 模块说明

面向管理后台的 Firebase 数据分析模块，提供事件总览、趋势、质量排行、错误分析、明细与筛选项接口。

统一前缀：`/api/v3/{admin_prefix}/firebase-analytics`

---

## 通用查询参数

适用于所有统计与趋势接口。

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| start_time | string | 否 | 今日 00:00:00 | 开始时间，格式 `YYYY-MM-DD HH:mm:ss` |
| end_time | string | 否 | 今日 23:59:59 | 结束时间，格式 `YYYY-MM-DD HH:mm:ss` |
| time_field | string | 否 | received_at | `event_time` 或 `received_at` |
| app_id | string | 否 | - | App ID |
| platform | string | 否 | - | `android` / `ios` |
| app_version | string | 否 | - | App 版本 |
| user_country | string | 否 | - | 用户国家 |
| user_region | string | 否 | - | 用户地区 |
| network_type | string | 否 | - | `wifi` / `cellular` |
| isp | string | 否 | - | 运营商 |
| asn | string | 否 | - | ASN |
| event_name | string | 否 | - | 事件名称 |
| interval | string | 否 | 自动兜底 | `5m` / `15m` / `1h` / `1d` |

---

## 缓存建议

建议缓存 30~60 秒：

- `/dashboard/summary`
- `/dashboard/event-trend`
- `/dashboard/region-quality`
- `/errors/top`
- `/nodes/quality-rank`
- `/vpn-session/quality-trend`
- `/app-open/trend`
- `/vpn-probe/node-rank`

不建议缓存：

- `/events`
- `/events/{event_id}`
- `/vpn-probe/results`

说明：统计、趋势和排行接口默认使用 60 秒服务端缓存；事件明细和探测结果明细保持实时查询，不做服务端缓存。

## 分页参数约定

- 事件明细与 Firebase Analytics 明细接口使用 `page` / `page_size`。
- 最近事件接口保留 Redis 列表接口既有参数 `page` / `pageSize`。
- Firebase 聚合报表接口保留管理端报表既有参数 `page` / `pageSize`。

---

## 接口列表

| 接口名称 | 请求方法 | 请求路径 | 说明 |
|---|---|---|---|
| Dashboard 总览 | GET | /dashboard/summary | 首页 KPI 卡片 |
| Dashboard 事件趋势 | GET | /dashboard/event-trend | 事件趋势 |
| VPN 质量趋势 | GET | /vpn-session/quality-trend | VPN 连接质量趋势 |
| 地区质量分布 | GET | /dashboard/region-quality | 地区质量分布 |
| 错误 Top 排行 | GET | /errors/top | 错误排行 |
| 节点质量排行 | GET | /nodes/quality-rank | 节点质量排行 |
| App 打开汇总 | GET | /app-open/summary | App 打开汇总 |
| App 打开趋势 | GET | /app-open/trend | App 打开趋势 |
| 启动类型分布 | GET | /app-open/open-type-distribution | 启动类型分布 |
| 版本启动性能排行 | GET | /app-open/version-rank | 版本启动性能排行 |
| VPN 汇总 | GET | /vpn-session/summary | VPN 连接汇总 |
| 失败阶段分布 | GET | /vpn-session/fail-stage-distribution | 失败阶段分布 |
| 错误阶段分布 | GET | /vpn-session/error-stage-distribution | 错误阶段分布 |
| 连接方式分析 | GET | /vpn-session/connect-type-analysis | 连接方式分析 |
| 协议质量对比 | GET | /vpn-session/protocol-quality | 协议质量对比 |
| 测速汇总 | GET | /vpn-probe/summary | 节点测速汇总 |
| 测速趋势 | GET | /vpn-probe/trend | 节点测速趋势 |
| 测速触发场景分布 | GET | /vpn-probe/trigger-distribution | 测速触发场景分布 |
| 测速类型分布 | GET | /vpn-probe/type-distribution | 测速类型分布 |
| 节点测速排行 | GET | /vpn-probe/node-rank | 节点测速排行 |
| API 错误汇总 | GET | /server-api-error/summary | API 错误汇总 |
| API 错误趋势 | GET | /server-api-error/trend | API 错误趋势 |
| HTTP 状态码分布 | GET | /server-api-error/http-status-distribution | HTTP 状态码分布 |
| API 路径排行 | GET | /server-api-error/api-rank | API 路径错误排行 |
| 最近接收事件 | GET | /events/recent | Redis 最近接收事件分页 |
| 事件列表 | GET | /events | 事件明细列表 |
| 事件详情 | GET | /events/{event_id} | 事件详情 |
| 筛选项 | GET | /filters/options | 前端筛选项 |

---

## 接口详情

### 1. Dashboard 总览

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/dashboard/summary`
- 权限要求：`admin`
- 使用场景：首页 KPI 卡片

#### 请求参数

继承通用查询参数。

#### 返回字段

| 字段名 | 类型 | 说明 |
|---|---|---|
| code | int | 状态码 |
| msg | string | 返回消息 |
| data.total_events | int | 总事件数 |
| data.active_devices | int | 活跃设备数 |
| data.app_open_count | int | App 打开事件数 |
| data.vpn_session_count | int | VPN 会话数 |
| data.vpn_success_rate | float | VPN 成功率 |
| data.probe_success_rate | float | 测速成功率 |
| data.api_error_count | int | API 错误事件数 |
| data.duplicate_event_count | int | 重复事件数 |
| data.avg_receive_delay_ms | int | 平均上报延迟 |
| data.compare | object | 与上一周期对比 |

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "total_events": 128560,
    "active_devices": 8421,
    "app_open_count": 24560,
    "vpn_session_count": 38720,
    "vpn_success_rate": 0.9623,
    "probe_success_rate": 0.9341,
    "api_error_count": 1320,
    "duplicate_event_count": 85,
    "avg_receive_delay_ms": 820,
    "compare": {
      "total_events_rate": 0.125,
      "active_devices_rate": 0.083,
      "app_open_rate": 0.097,
      "vpn_success_rate_diff": 0.012,
      "probe_success_rate_diff": 0.008,
      "api_error_rate": 0.236
    }
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| total_events | firebase_event_common | 事件总数 |
| active_devices | firebase_event_common.device_id | 按 device_id 去重 |
| app_open_count | firebase_event_common.event_name | event_name=app_open |
| vpn_session_count | firebase_event_common.event_name | event_name=vpn_session |
| vpn_success_rate | firebase_event_vpn_session.success | 成功数 / 总数 |
| probe_success_rate | firebase_event_vpn_probe.success_count/fail_count | 成功数 / (成功+失败) |
| api_error_count | firebase_event_common.event_name | event_name=server_api_error |
| duplicate_event_count | firebase_event_common.duplicate_count | 累加 |
| avg_receive_delay_ms | event_time_ms/received_at | (received_at - event_time_ms) 平均 |

---

### 2. Dashboard 事件趋势

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/dashboard/event-trend`
- 权限要求：`admin`
- 使用场景：事件趋势折线图

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "interval": "1h",
    "items": [
      {
        "time": "2026-05-14 00:00:00",
        "total": 8760,
        "app_open": 1620,
        "vpn_session": 3540,
        "vpn_probe": 2280,
        "server_api_error": 420
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| total | firebase_event_common | 事件总数 |
| app_open | firebase_event_common.event_name | event_name=app_open |
| vpn_session | firebase_event_common.event_name | event_name=vpn_session |
| vpn_probe | firebase_event_common.event_name | event_name=vpn_probe |
| server_api_error | firebase_event_common.event_name | event_name=server_api_error |

---

### 3. VPN 质量趋势

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-session/quality-trend`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "interval": "1h",
    "items": [
      {
        "time": "2026-05-14 10:00:00",
        "session_count": 500,
        "success_count": 480,
        "fail_count": 20,
        "success_rate": 0.96,
        "avg_connect_ms": 420,
        "p95_connect_ms": 1200,
        "avg_duration_ms": 860000,
        "retry_count": 72
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| session_count | firebase_event_vpn_session | 会话数 |
| success_count | firebase_event_vpn_session.success | 成功数 |
| fail_count | firebase_event_vpn_session.success | 失败数 |
| avg_connect_ms | firebase_event_vpn_session.connect_ms | 平均连接耗时 |
| p95_connect_ms | firebase_event_vpn_session.connect_ms | P95 |
| avg_duration_ms | firebase_event_vpn_session.duration_ms | 平均时长 |
| retry_count | firebase_event_vpn_session.retry_count | 重试次数累计 |

---

### 4. 地区质量分布

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/dashboard/region-quality`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

额外参数：

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| sort_by | string | 否 | event_count | `event_count` / `vpn_success_rate` / `api_error_count` / `avg_connect_ms` |
| order | string | 否 | desc | `asc` / `desc` |
| limit | int | 否 | 50 | 返回条数 |

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "user_country": "SG",
        "user_region": "Singapore",
        "event_count": 12342,
        "active_devices": 980,
        "vpn_session_count": 3600,
        "vpn_success_rate": 0.9812,
        "api_error_count": 98,
        "avg_connect_ms": 298
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| event_count | firebase_event_common | 事件总数 |
| active_devices | firebase_event_common.device_id | 去重设备 |
| vpn_session_count | firebase_event_common.event_name | event_name=vpn_session |
| vpn_success_rate | firebase_event_vpn_session.success | 成功数 / 总数 |
| api_error_count | firebase_event_common.event_name | event_name=server_api_error |
| avg_connect_ms | firebase_event_vpn_session.connect_ms | 平均连接耗时 |

---

### 5. 错误 Top 排行

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/errors/top`
- 权限要求：`admin`

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| error_type | string | 是 | - | `vpn_session` / `vpn_probe` / `server_api` |
| limit | int | 否 | 10 | 返回条数 |

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "error_type": "vpn_session",
    "items": [
      {
        "rank": 1,
        "error_stage": "dns",
        "error_code": "DNS_FAILED",
        "count": 1230,
        "ratio": 0.3218,
        "affected_devices": 842
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| error_type | 表 | 统计字段 |
|---|---|---|
| vpn_session | firebase_event_vpn_session | error_stage/error_code |
| vpn_probe | firebase_event_vpn_probe_result | error_code |
| server_api | firebase_event_server_api_error | api_domain/api_path/http_status/error_code |

---

### 6. 节点质量排行

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/nodes/quality-rank`
- 权限要求：`admin`

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| source | string | 否 | session | `session` / `probe` / `mixed` |
| sort_by | string | 否 | session_count | `success_rate` / `avg_connect_ms` / `p95_connect_ms` / `total_bytes` / `session_count` |
| order | string | 否 | desc | `asc` / `desc` |
| limit | int | 否 | 20 | 返回条数 |

说明：`success_rate`、`p95_connect_ms` 等计算字段支持排序，服务端会按数据源映射到安全的聚合表达式；`source=probe` 时 `session_count` 表示探测结果数；`source=mixed` 当前按 `session` 口径兼容处理。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "source": "session",
    "items": [
      {
        "rank": 1,
        "node_id": "node-sg-01",
        "node_name": "Singapore 01",
        "node_country": "SG",
        "node_region": "Singapore",
        "protocol": "vless_reality",
        "session_count": 3420,
        "success_count": 3360,
        "success_rate": 0.9824,
        "avg_connect_ms": 320,
        "p95_connect_ms": 900,
        "avg_duration_ms": 1100000,
        "total_bytes": 88400000000,
        "top_error_code": "TIMEOUT"
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| source | 表 | 说明 |
|---|---|---|
| session | firebase_event_vpn_session | 会话维度聚合 |
| probe | firebase_event_vpn_probe_result | 测速结果维度聚合 |

---

### 7. App 打开汇总

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/app-open/summary`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "open_count": 24560,
    "active_devices": 8421,
    "avg_launch_ms": 680,
    "p95_launch_ms": 2100,
    "cold_start_ratio": 0.42,
    "top_install_channel": "google_play"
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| open_count | firebase_event_common.event_name | event_name=app_open |
| active_devices | firebase_event_common.device_id | 去重设备 |
| avg_launch_ms | firebase_event_app_open.launch_ms | 平均启动耗时 |
| p95_launch_ms | firebase_event_app_open.launch_ms | P95 |
| cold_start_ratio | firebase_event_app_open.open_type | cold_start / 总数 |
| top_install_channel | firebase_event_app_open.install_channel | Top1 渠道 |

---

### 8. App 打开趋势

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/app-open/trend`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "interval": "1h",
    "items": [
      {
        "time": "2026-05-14 10:00:00",
        "open_count": 1200,
        "active_devices": 620,
        "avg_launch_ms": 710,
        "p95_launch_ms": 2200
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| open_count | firebase_event_app_open | 数量 |
| active_devices | firebase_event_common.device_id | 去重设备 |
| avg_launch_ms | firebase_event_app_open.launch_ms | 平均启动耗时 |
| p95_launch_ms | firebase_event_app_open.launch_ms | P95 |

---

### 9. 启动类型分布

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/app-open/open-type-distribution`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "open_type": "cold_start",
        "count": 10200,
        "ratio": 0.415
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| open_type | firebase_event_app_open.open_type | 启动类型 |
| count | firebase_event_app_open | 数量 |

---

### 10. 版本启动性能排行

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/app-open/version-rank`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

额外参数：

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| limit | int | 否 | 20 | 返回条数 |

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "app_version": "1.0.0",
        "open_count": 12000,
        "active_devices": 4200,
        "avg_launch_ms": 720,
        "p95_launch_ms": 2300,
        "cold_start_ratio": 0.41
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| app_version | firebase_event_common.app_version | 版本 |
| open_count | firebase_event_common.event_name | event_name=app_open |
| active_devices | firebase_event_common.device_id | 去重设备 |

---

### 11. VPN 汇总

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-session/summary`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "session_count": 38720,
    "success_count": 37260,
    "fail_count": 1460,
    "success_rate": 0.9623,
    "avg_connect_ms": 420,
    "p95_connect_ms": 1200,
    "avg_duration_ms": 860000,
    "total_upload_bytes": 32000000000,
    "total_download_bytes": 156000000000,
    "total_bytes": 188000000000,
    "retry_session_count": 2900,
    "retry_rate": 0.0749
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| session_count | firebase_event_vpn_session | 会话数 |
| success_count | firebase_event_vpn_session.success | 成功数 |
| fail_count | firebase_event_vpn_session.success | 失败数 |
| avg_connect_ms | firebase_event_vpn_session.connect_ms | 平均连接耗时 |
| p95_connect_ms | firebase_event_vpn_session.connect_ms | P95 |
| avg_duration_ms | firebase_event_vpn_session.duration_ms | 平均时长 |
| total_upload_bytes | firebase_event_vpn_session.upload_bytes | 上传总量 |
| total_download_bytes | firebase_event_vpn_session.download_bytes | 下载总量 |
| total_bytes | firebase_event_vpn_session.total_bytes | 总流量 |
| retry_session_count | firebase_event_vpn_session.retry_count | 重试次数累计 |

---

### 12. 失败阶段分布

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-session/fail-stage-distribution`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "fail_stage": "connect",
        "count": 940,
        "ratio": 0.6438
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| fail_stage | firebase_event_vpn_session.fail_stage | 失败阶段 |
| count | firebase_event_vpn_session | 数量 |

---

### 13. 错误阶段分布

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-session/error-stage-distribution`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "error_stage": "dns",
        "count": 1230,
        "ratio": 0.3218
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| error_stage | firebase_event_vpn_session.error_stage | 错误阶段 |
| count | firebase_event_vpn_session | 数量 |

---

### 14. 连接方式分析

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-session/connect-type-analysis`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "connect_type": "auto",
        "session_count": 18200,
        "success_rate": 0.972,
        "avg_connect_ms": 380,
        "retry_rate": 0.061
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| connect_type | firebase_event_vpn_session.connect_type | 连接方式 |
| session_count | firebase_event_vpn_session | 数量 |
| success_rate | firebase_event_vpn_session.success | 成功率 |
| avg_connect_ms | firebase_event_vpn_session.connect_ms | 平均连接耗时 |
| retry_rate | firebase_event_vpn_session.retry_count | 重试率 |

---

### 15. 协议质量对比

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-session/protocol-quality`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "protocol": "vless_reality",
        "session_count": 31200,
        "success_rate": 0.963,
        "avg_connect_ms": 410,
        "avg_duration_ms": 920000,
        "top_error_code": "TIMEOUT"
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| protocol | firebase_event_vpn_session.protocol | 协议 |
| session_count | firebase_event_vpn_session | 数量 |
| success_rate | firebase_event_vpn_session.success | 成功率 |
| avg_connect_ms | firebase_event_vpn_session.connect_ms | 平均连接耗时 |
| avg_duration_ms | firebase_event_vpn_session.duration_ms | 平均时长 |
| top_error_code | firebase_event_vpn_session.error_code | Top1 错误码 |

---

### 16. 测速汇总

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-probe/summary`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "probe_count": 8200,
    "probe_result_count": 98400,
    "avg_probe_success_rate": 0.9341,
    "avg_latency_ms": 180,
    "p95_latency_ms": 820,
    "avg_duration_ms": 3200,
    "failed_result_count": 6480
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| probe_count | firebase_event_vpn_probe | 测速事件数 |
| probe_result_count | firebase_event_vpn_probe.node_count | 节点数累计 |
| avg_probe_success_rate | firebase_event_vpn_probe.success_count/fail_count | 成功率 |
| avg_latency_ms | firebase_event_vpn_probe_result.latency_ms | 平均延迟 |
| p95_latency_ms | firebase_event_vpn_probe_result.latency_ms | P95 |
| avg_duration_ms | firebase_event_vpn_probe.duration_ms | 平均耗时 |
| failed_result_count | firebase_event_vpn_probe.fail_count | 失败数 |

---

### 17. 测速趋势

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-probe/trend`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "interval": "1h",
    "items": [
      {
        "time": "2026-05-14 10:00:00",
        "probe_count": 420,
        "result_count": 5040,
        "success_rate": 0.941,
        "avg_latency_ms": 160,
        "avg_duration_ms": 2800
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| probe_count | firebase_event_vpn_probe | 测速事件数 |
| result_count | firebase_event_vpn_probe.node_count | 节点数累计 |
| success_rate | firebase_event_vpn_probe.success_count/fail_count | 成功率 |
| avg_latency_ms | firebase_event_vpn_probe_result.latency_ms | 平均延迟（由结果表统计） |
| avg_duration_ms | firebase_event_vpn_probe.duration_ms | 平均耗时 |

---

### 18. 测速触发场景分布

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-probe/trigger-distribution`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "probe_trigger": "app_open",
        "count": 3600,
        "ratio": 0.439
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| probe_trigger | firebase_event_vpn_probe.probe_trigger | 触发场景 |
| count | firebase_event_vpn_probe | 数量 |

---

### 19. 测速类型分布

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-probe/type-distribution`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "probe_type": "full_probe",
        "count": 4200,
        "ratio": 0.512
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| probe_type | firebase_event_vpn_probe.probe_type | 测速类型 |
| count | firebase_event_vpn_probe | 数量 |

---

### 20. 节点测速排行

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/vpn-probe/node-rank`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

额外参数：

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| sort_by | string | 否 | success_rate | `success_rate` / `avg_latency_ms` / `p95_latency_ms` / `avg_tcp_connect_ms` / `avg_tls_hk_ms` / `avg_proxy_hk_ms` |
| order | string | 否 | desc | `asc` / `desc` |
| limit | int | 否 | 20 | 返回条数 |

说明：`success_rate` 与 `p95_latency_ms` 为计算字段，服务端会先完成聚合与 P95 计算，再按请求参数安全排序。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "rank": 1,
        "node_id": "node-sg-01",
        "node_name": "Singapore 01",
        "node_country": "SG",
        "protocol": "vless_reality",
        "test_count": 5230,
        "success_rate": 0.991,
        "avg_latency_ms": 80,
        "p95_latency_ms": 230,
        "avg_tcp_connect_ms": 40,
        "avg_tls_hk_ms": 60,
        "avg_proxy_hk_ms": 90,
        "top_error_code": "NONE"
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| test_count | firebase_event_vpn_probe_result | 测试次数 |
| success_rate | firebase_event_vpn_probe_result.success | 成功率 |
| avg_latency_ms | firebase_event_vpn_probe_result.latency_ms | 平均延迟 |
| p95_latency_ms | firebase_event_vpn_probe_result.latency_ms | P95 |
| avg_tcp_connect_ms | firebase_event_vpn_probe_result.tcp_connect_ms | TCP 耗时 |
| avg_tls_hk_ms | firebase_event_vpn_probe_result.tls_hk_ms | TLS 耗时 |
| avg_proxy_hk_ms | firebase_event_vpn_probe_result.proxy_hk_ms | 代理握手耗时 |
| top_error_code | firebase_event_vpn_probe_result.error_code | Top1 错误码 |

---

### 21. API 错误汇总

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/server-api-error/summary`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "api_error_count": 1320,
    "affected_devices": 842,
    "http_5xx_count": 420,
    "http_4xx_count": 360,
    "timeout_count": 210,
    "business_error_count": 180,
    "avg_duration_ms": 1200,
    "retry_count": 260
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| api_error_count | firebase_event_server_api_error | 错误事件数 |
| affected_devices | firebase_event_common.device_id | 去重设备 |
| http_5xx_count | firebase_event_server_api_error.http_status | 5xx 数 |
| http_4xx_count | firebase_event_server_api_error.http_status | 4xx 数 |
| timeout_count | firebase_event_server_api_error.error_code | REQUEST_TIMEOUT 计数 |
| business_error_count | firebase_event_server_api_error.business_code | 非空计数 |
| avg_duration_ms | firebase_event_server_api_error.duration_ms | 平均耗时 |
| retry_count | firebase_event_server_api_error.retry_count | 重试数 |

---

### 22. API 错误趋势

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/server-api-error/trend`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "interval": "1h",
    "items": [
      {
        "time": "2026-05-14 10:00:00",
        "error_count": 80,
        "affected_devices": 46,
        "avg_duration_ms": 1300
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| error_count | firebase_event_server_api_error | 错误事件数 |
| affected_devices | firebase_event_common.device_id | 去重设备 |
| avg_duration_ms | firebase_event_server_api_error.duration_ms | 平均耗时 |

---

### 23. HTTP 状态码分布

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/server-api-error/http-status-distribution`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "http_status": 504,
        "count": 320,
        "ratio": 0.242
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| http_status | firebase_event_server_api_error.http_status | 状态码 |
| count | firebase_event_server_api_error | 数量 |

---

### 24. API 路径排行

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/server-api-error/api-rank`
- 权限要求：`admin`

#### 请求参数

继承通用查询参数。

额外参数：

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| sort_by | string | 否 | error_count | `error_count` / `avg_duration_ms` |
| order | string | 否 | desc | `asc` / `desc` |
| limit | int | 否 | 20 | 返回条数 |

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "items": [
      {
        "rank": 1,
        "api_domain": "api.example.com",
        "api_path": "/v1/node/list",
        "http_method": "GET",
        "error_count": 420,
        "main_http_status": 504,
        "main_error_code": "REQUEST_TIMEOUT",
        "avg_duration_ms": 1500,
        "affected_devices": 280
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 指标 | 表/字段 | 说明 |
|---|---|---|
| api_domain/api_path/http_method | firebase_event_server_api_error | 聚合维度 |
| error_count | firebase_event_server_api_error | 错误数 |
| main_http_status | firebase_event_server_api_error.http_status | 最大值近似 |
| main_error_code | firebase_event_server_api_error.error_code | 最大值近似 |
| avg_duration_ms | firebase_event_server_api_error.duration_ms | 平均耗时 |
| affected_devices | firebase_event_common.device_id | 去重设备 |

---

### 25. 最近接收事件

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/events/recent`
- 权限要求：`admin`

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| page | int | 否 | 1 | 当前页（从 1 开始） |
| pageSize | int | 否 | 20 | 每页数量，最大 200 |

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "page": 1,
    "pageSize": 20,
    "total": 500,
    "items": [
      {
        "recv_at": "2026-05-19T09:30:12.123456789Z",
        "event_id": "evt_01",
        "app_id": "com.demo.app",
        "event_name": "app_open",
        "platform": "android",
        "queue_file": "/var/lib/firebase-event-recv/queue/pending/20260519T093012.123456789_xxx.json",
        "raw": {
          "app_id": "com.demo.app",
          "event_id": "evt_01",
          "event_name": "app_open",
          "platform": "android",
          "app_version": "1.0.0",
          "device_id": "dev_01",
          "event_time": 1710000000000,
          "created_at": 1710000000000,
          "open_type": "cold_start",
          "launch_ms": 430
        }
      }
    ]
  }
}
```

---

#### 数据来源

| 字段 | 来源 | 说明 |
|---|---|---|
| total | Redis `LLEN` | Key 为 `firebase-event-recv:recent:events` |
| items | Redis `LRANGE` | List 元素为 JSON 字符串，按 `LPUSH` 顺序返回 |

---

### 26. 事件列表

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/events`
- 权限要求：`admin`

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| page | int | 否 | 1 | 当前页 |
| page_size | int | 否 | 20 | 每页数量 |
| start_time | string | 否 | 今日 00:00:00 | 开始时间 |
| end_time | string | 否 | 今日 23:59:59 | 结束时间 |
| time_field | string | 否 | received_at | `event_time` / `received_at` |
| event_id | string | 否 | - | 事件 ID |
| device_id | string | 否 | - | 设备 ID |
| user_id | string | 否 | - | 用户 ID |
| app_id | string | 否 | - | App ID |
| platform | string | 否 | - | 平台 |
| app_version | string | 否 | - | App 版本 |
| event_name | string | 否 | - | 事件名称 |
| user_country | string | 否 | - | 国家 |
| network_type | string | 否 | - | 网络类型 |
| isp | string | 否 | - | 运营商 |
| asn | string | 否 | - | ASN |
| node_id | string | 否 | - | 节点 ID |
| api_path | string | 否 | - | API 路径 |
| trace_id | string | 否 | - | Trace ID |
| error_code | string | 否 | - | 错误码 |
| success | bool | 否 | - | 是否成功 |

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "page": 1,
    "page_size": 20,
    "total": 10000,
    "items": [
      {
        "event_id": "evt_xxx",
        "event_name": "vpn_session",
        "received_at": "2026-05-14 10:12:33.123",
        "event_time_ms": 1778734353123,
        "app_id": "test.app.pupu",
        "platform": "android",
        "app_version": "1.0.0",
        "device_id": "device_xxx",
        "user_id": "user_xxx",
        "user_country": "SG",
        "network_type": "wifi",
        "isp": "Singtel",
        "asn": "AS3758",
        "success": false,
        "error_stage": "dns",
        "error_code": "DNS_FAILED",
        "duplicate_count": 0
      }
    ]
  }
}
```

---

#### 统计口径与数据来源

| 字段 | 表 | 说明 |
|---|---|---|
| items | firebase_event_common | 基础事件信息 |
| success/api_path/trace_id | 扩展表 | 通过子查询过滤 |

---

### 27. 事件详情

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/events/{event_id}`
- 权限要求：`admin`

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| event_id | string | 是（路径） | - | 事件 ID |

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "common": {
      "event_id": "evt_xxx",
      "app_id": "test.app.pupu",
      "event_name": "vpn_session",
      "platform": "android",
      "app_version": "1.0.0",
      "device_id": "device_xxx",
      "user_id": "user_xxx",
      "user_country": "SG",
      "user_region": "Singapore",
      "language": "zh-CN",
      "network_type": "wifi",
      "isp": "Singtel",
      "asn": "AS3758",
      "event_time_ms": 1778734353123,
      "created_at_ms": 1778734353000,
      "received_at": "2026-05-14 10:12:33.123",
      "firebase_event_id": "firebase_xxx",
      "firebase_event_time": "2026-05-14T10:12:33Z",
      "firebase_source": "firestore-trigger",
      "forwarded_at": "2026-05-14T10:12:34Z",
      "duplicate_count": 0,
      "last_duplicate_at": null
    },
    "extension": {
      "session_id": "sess_xxx",
      "node_id": "node-sg-01",
      "node_host": "sg01.example.com",
      "node_name": "Singapore 01",
      "node_country": "SG",
      "protocol": "vless_reality",
      "connect_type": "auto",
      "success": false,
      "connect_ms": 3000,
      "error_stage": "dns",
      "error_code": "DNS_FAILED",
      "error_message": "dns lookup failed",
      "retry_count": 1
    }
  }
}
```

---

#### 数据来源

| event_name | 扩展表 |
|---|---|
| app_open | firebase_event_app_open |
| vpn_session | firebase_event_vpn_session |
| vpn_probe | firebase_event_vpn_probe + firebase_event_vpn_probe_result |
| server_api_error | firebase_event_server_api_error |

---

### 28. 筛选项

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/filters/options`
- 权限要求：`admin`

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "apps": [{"label": "test.app.pupu", "value": "test.app.pupu"}],
    "platforms": [{"label": "Android", "value": "android"}],
    "versions": [{"label": "1.0.0", "value": "1.0.0"}],
    "countries": [{"label": "Singapore", "value": "SG"}],
    "network_types": [{"label": "WiFi", "value": "wifi"}],
    "isps": [{"label": "Singtel", "value": "Singtel"}],
    "asns": [{"label": "AS3758", "value": "AS3758"}],
    "event_names": [
      {"label": "App 打开", "value": "app_open"},
      {"label": "VPN 连接", "value": "vpn_session"},
      {"label": "节点测速", "value": "vpn_probe"},
      {"label": "API 错误", "value": "server_api_error"}
    ]
  }
}
```

---

## 错误码

| code | msg | 说明 |
|---|---|---|
| 422 | 参数验证失败 | 请求参数不合法 |
| 500 | 操作失败 | 服务端异常 |

---

## 相关文件

- `app/Http/Routes/V3/AdminRoute.php`
- `app/Http/Controllers/V3/Admin/FirebaseAnalytics*Controller.php`
- `app/Http/Requests/Admin/FirebaseAnalytics*Request.php`
- `app/Services/FirebaseAnalytics*Service.php`

---

## 接口补充：探测结果明细

### GET /vpn-probe/node-stats

分页查看节点维度的探测统计，用于检查节点探测成功率、失败数、延迟和主要错误码。

#### 请求参数

继承通用查询参数；未传 `start_time` / `end_time` 时默认查询今日。

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| page | int | 否 | 1 | 当前页 |
| page_size | int | 否 | 20 | 每页数量，最大 200 |
| probe_id | string | 否 | - | 批量探测 ID |
| node_id | string | 否 | - | 节点 ID |
| node_name | string | 否 | - | 节点名称 |
| node_country | string | 否 | - | 节点国家或地区 |
| node_region | string | 否 | - | 节点区域 |
| protocol | string | 否 | - | 协议 |
| sort_by | string | 否 | success_rate | `node_id` / `test_count` / `success_count` / `fail_count` / `success_rate` / `avg_latency_ms` / `p95_latency_ms` / `avg_tcp_connect_ms` / `avg_tls_hk_ms` / `avg_proxy_hk_ms` / `last_received_at` |
| order | string | 否 | desc | `asc` / `desc` |

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "page": 1,
    "page_size": 20,
    "total": 1,
    "items": [
      {
        "node_id": "node-sg-01",
        "node_name": "Singapore 01",
        "node_country": "SG",
        "node_region": "Singapore",
        "protocol": "vless_reality",
        "test_count": 100,
        "success_count": 96,
        "fail_count": 4,
        "success_rate": 0.96,
        "avg_latency_ms": 120,
        "p95_latency_ms": 300,
        "avg_tcp_connect_ms": 80,
        "avg_tls_hk_ms": 90,
        "avg_proxy_hk_ms": 100,
        "top_error_code": "PROBE_TIMEOUT",
        "last_received_at": "2026-06-29 10:00:00"
      }
    ]
  }
}
```

#### 数据来源

| 字段 | 表 | 说明 |
|---|---|---|
| 节点维度与探测耗时 | firebase_event_vpn_probe_result | 按节点、区域、协议聚合探测结果 |
| last_received_at | firebase_event_common | 节点最近一次探测上报时间 |
| top_error_code | firebase_event_vpn_probe_result | 节点维度出现次数最多的错误码 |

### GET /vpn-probe/results

分页查看 `firebase_event_vpn_probe_result` 单节点探测结果明细。该接口用于明细排查；统计分析仍使用 `/vpn-probe/summary`、`/vpn-probe/trend` 和 `/vpn-probe/node-rank`。

#### 请求参数

继承通用查询参数；未传 `start_time` / `end_time` 时默认查询今日。

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| page | int | 否 | 1 | 当前页 |
| page_size | int | 否 | 20 | 每页数量，最大 200 |
| event_id | string | 否 | - | Firebase 事件 ID |
| probe_id | string | 否 | - | 批量探测 ID |
| node_id | string | 否 | - | 节点 ID |
| node_name | string | 否 | - | 节点名称 |
| node_country | string | 否 | - | 节点国家或地区 |
| node_region | string | 否 | - | 节点区域 |
| protocol | string | 否 | - | 协议 |
| success | bool | 否 | - | 是否成功 |
| error_code | string | 否 | - | 错误码 |
| sort_by | string | 否 | received_at | `received_at` / `result_index` / `latency_ms` / `tcp_connect_ms` / `tls_hk_ms` / `proxy_hk_ms` / `timeout_ms` / `id` |
| order | string | 否 | desc | `asc` / `desc` |

#### 返回示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "page": 1,
    "page_size": 20,
    "total": 1,
    "items": [
      {
        "id": 1001,
        "event_id": "evt_probe_001",
        "received_at": "2026-06-29 10:00:00",
        "event_time_ms": 1782708000000,
        "app_id": "com.example.vpn",
        "platform": "android",
        "app_version": "1.0.0",
        "device_id": "device_001",
        "user_id": "user_001",
        "user_country": "SG",
        "network_type": "wifi",
        "probe_id": "probe_001",
        "probe_type": "full_probe",
        "probe_trigger": "manual_refresh",
        "result_index": 0,
        "node_id": "node-sg-01",
        "node_name": "Singapore 01",
        "node_country": "SG",
        "node_region": "Singapore",
        "protocol": "vless_reality",
        "success": 1,
        "latency_ms": 120,
        "tcp_connect_ms": 80,
        "tls_hk_ms": 90,
        "proxy_hk_ms": 100,
        "error_code": null,
        "error_message": null,
        "timeout_ms": 3000
      }
    ]
  }
}
```

#### 数据来源

| 字段 | 表 | 说明 |
|---|---|---|
| 事件信息 | firebase_event_common | 时间、App、设备、用户、地区、网络信息 |
| probe_id / probe_type / probe_trigger | firebase_event_vpn_probe | 批量探测上下文 |
| 节点与耗时结果 | firebase_event_vpn_probe_result | 单节点探测结果明细 |
