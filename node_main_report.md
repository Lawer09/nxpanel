# 节点主报表（node_main_report）设计归纳

## 1. 目标

围绕一个“主表”同时承载业务与排障的核心视图：

- 支持统一维度筛选与聚合（POST 传 `groupBy`）
- 指标统一输出，减少前端多报表切换
- 明确区分两类流量口径：
  - 节点上报流量（node push）
  - 客户端上报流量（client report）

> 错误归因明细表、实时状态表作为后续独立建设，不放入本主表。

---

## 2. 维度定义（主表）

主表支持以下维度（可按需组合聚合）：

- `date`（日期）
- `hour`（小时）
- `node_id`（节点ID）
- `node_name`（节点名称）
- `app_id`（包名）
- `app_version`（版本）
- `platform`（客户端OS）
- `client_country`（用户网络国家）
- `client_isp`（用户网络ISP）
- `node_host`（节点Host）
- `machine_ip`（机器IP）
- `machine_ip_isp`（机器IP的ISP/组织）
- `node_protocol`（节点协议）

### 空值统一规则

维度字段若为空、null、空字符串，统一显示为：`未知`。

建议后端统一处理：

`COALESCE(NULLIF(field, ''), '未知')`

---

## 3. 聚合值（指标）定义

主表输出以下聚合值：

- `avg_delay`：平均延迟（加权）
- `success_count`：成功数
- `failed_count`：失败数（建议口径：`failed + timeout + cancelled`）
- `node_push_traffic_u_bytes`：节点上报上行流量
- `node_push_traffic_d_bytes`：节点上报下行流量
- `node_push_traffic_total_bytes`：节点上报总流量
- `client_report_traffic_usage_mb`：客户端上报流量（MB）
- `client_report_usage_seconds`：客户端上报使用时长（秒）
- `client_report_count`：客户端上报次数
- `bandwidth`：总带宽（见第7节）
- `up_bandwidth`：上行带宽（见第7节）
- `down_bandwidth`：下行带宽（见第7节）
- `node_connect_error_count`：`node_connect` 阶段错误数
- `post_connect_probe_error_count`：`post_connect_probe` 阶段错误数

---

## 4. 字段来源表（按指标/维度）

## 4.1 核心聚合来源

- `v2_node_performance_aggregated`
  - 时间：`date`, `hour`, `minute`
  - 维度：`node_id`, `client_country`, `platform`, `client_isp`, `app_id`, `app_version`
  - 指标：`avg_delay`, `avg_success_rate`, `total_count`

- `v2_node_probe_aggregated`
  - 时间：`date`, `hour`, `minute`
  - 维度：`node_id`, `node_ip`, `client_country`, `platform`, `client_isp`, `app_id`, `app_version`, `probe_stage`, `status`, `error_code`
  - 指标：`total_count`（用于成功/失败/分阶段错误）

- `v2_node_traffic_aggregated`（客户端上报流量）
  - 时间：`date`, `hour`, `minute`
  - 维度：`user_id`, `node_id`, `node_ip`, `client_country`, `platform`, `client_isp`, `app_id`, `app_version`
  - 指标：`total_usage_seconds`, `total_usage_mb`, `report_count`

- `v2_stat_server_detail`（节点上报流量）
  - 时间：`year`, `month`, `day`, `hour`, `minute`, `record_at`
  - 维度：`server_id`, `server_type`
  - 指标：`u`, `d`（字节）

## 4.2 维表来源

- `v2_server`
  - `id -> node_id`
  - `name -> node_name`
  - `host -> node_host`
  - `type -> node_protocol`
  - `machine_id`

- `machines`
  - `id -> machine_id`
  - `ip_address -> machine_ip`

- `ip_machine` + `v2_ip_pool`
  - 机器与IP绑定关系（含 `is_primary`, `is_egress`, `bind_status`）
  - `v2_ip_pool.org -> machine_ip_isp`

- `v2_user.register_metadata`（补充来源）
  - 可用于补全/校验 app 相关维度
  - 主报表 app 维度优先使用性能上报链路字段（非必须依赖 register_metadata）

---

## 5. 两类流量口径（必须拆分）

## 5.1 节点上报流量（node push）

- 来源：`v2_stat_server_detail`（`u`, `d`）
- 语义：节点侧上报并入计费/流量链路的服务端视角流量
- 指标：
  - `node_push_traffic_u_bytes = SUM(u)`
  - `node_push_traffic_d_bytes = SUM(d)`
  - `node_push_traffic_total_bytes = SUM(u + d)`

## 5.2 客户端上报流量（client report）

- 来源：`v2_node_traffic_aggregated`（`total_usage_mb`, `total_usage_seconds`, `report_count`）
- 语义：客户端在性能上报中提交的使用时长/流量
- 指标：
  - `client_report_traffic_usage_mb = SUM(total_usage_mb)`
  - `client_report_usage_seconds = SUM(total_usage_seconds)`
  - `client_report_count = SUM(report_count)`

---

## 6. 维度可聚合性与限制

由于两类流量来源粒度不同，需约束可聚合性：

- `client_report_traffic_*`：可按主表全部客户端维度聚合（包含 app/version/platform/country/isp）
- `node_push_traffic_*`：当前稳定维度为时间 + 节点（`server_id/node_id`）+ 协议（`server_type`）

当 `groupBy` 包含客户端侧维度（如 `app_id/platform/client_isp`）时：

- `client_report_traffic_*` 正常返回
- `node_push_traffic_*` 建议返回 `null`（或返回0并附可用性标记）

避免把节点流量错误分摊到客户端维度导致“假精确”。

---

## 7. 带宽字段说明（当前阶段）

`bandwidth / up_bandwidth / down_bandwidth` 当前可选口径：

1. 实时口径（缓存 metrics）
   - 来自节点 `metrics.inbound_speed / outbound_speed`
   - 优点：接近实时
   - 限制：历史可追溯性弱（主要在缓存）

2. 资源配置口径（静态）
   - 来自 `machines.bandwidth` 或 `v2_ip_pool.bandwidth`
   - 优点：稳定可取
   - 限制：非实时吞吐

建议主表先输出字段并标注口径，后续在“实时状态表”中完善历史带宽体系。

---

## 8. 前端查询接口建议（主表）

- 方法/路径：`POST /api/v3/admin/report/node/query`
- 说明：`groupBy` 支持数组动态聚合；统一返回维度 + 指标

主表请求参数由 `FormRequest` 统一校验：

- `App\\Http\\Requests\\Admin\\NodeMainReportQueryRequest`

实现说明（当前版本）：

- 查询接口从 `v2_node_main_report_aggregated` 读取（不直接在线 join 多源明细表）
- 聚合任务：`php artisan perf:aggregate-main-table`
- 调度频率：每 5 分钟（聚合上一 5 分钟桶）
- 按天重建：`php artisan perf:rebuild-main-table-day 2026-05-07`
- 按天重建（保留已聚合数据并补算）：`php artisan perf:rebuild-main-table-day 2026-05-07 --keep-existing`

请求体建议：

```json
{
  "dateFrom": "2026-05-01",
  "dateTo": "2026-05-06",
  "groupBy": [
    "date", "hour", "node_id", "node_name", "app_id", "app_version",
    "platform", "client_country", "client_isp", "node_host",
    "machine_ip", "machine_ip_isp", "node_protocol"
  ],
  "filters": {
    "nodeIds": [12],
    "appIds": ["com.demo.app"],
    "platforms": ["android"],
    "includeExternal": false
  },
  "fillUnknown": true,
  "page": 1,
  "pageSize": 50
}
```

返回建议额外包含：

- `metric_availability`：指标可用性标记
  - 例如：
    - `node_push_traffic: full | unavailable_by_group`
    - `client_report_traffic: full`

---

## 8.1 子表校对查询接口（用于核对口径）

- 方法/路径：`POST /api/v3/admin/report/node/subtable/query`
- 说明：从各子表直接聚合查询，便于与主表结果交叉校验
- 请求校验：`App\\Http\\Requests\\Admin\\NodeSubReportQueryRequest`

`subTable` 可选值：

- `performance` -> `v2_node_performance_aggregated`
- `probe` -> `v2_node_probe_aggregated`
- `traffic` -> `v2_node_traffic_aggregated`
- `server_detail` -> `v2_stat_server_detail`
- `main_aggregated` -> `v2_node_main_report_aggregated`

请求体示例：

```json
{
  "subTable": "probe",
  "date": "2026-05-07",
  "hour": 13,
  "minute": 25,
  "groupBy": ["date", "hour", "minute", "node_id", "status", "probe_stage"],
  "filters": {
    "nodeIds": [12],
    "appIds": ["com.demo.app"],
    "platforms": ["android"],
    "statuses": ["success", "failed", "timeout", "cancelled"],
    "probeStages": ["node_connect", "post_connect_probe"],
    "includeExternal": false
  },
  "page": 1,
  "pageSize": 50
}
```

返回字段包含：

- `data`: 按 `groupBy` 返回聚合结果
- `metricMap`: 当前子表返回的指标字段列表
- `subTable/groupBy/date/hour/minute`: 本次查询上下文

常见校对方式：

1. 先查 `main_aggregated`，确认主表桶内结果。
2. 再分别查 `performance/probe/traffic/server_detail` 同桶同维度。
3. 对比主表指标与子表聚合指标是否一致（允许四舍五入差异）。

---

## 9. 主表最小落地口径（推荐）

第一期优先保证数据正确：

- 维度：全量支持（含“未知”归一）
- 指标：
  - `avg_delay`
  - `success_count` / `failed_count`
  - `node_connect_error_count` / `post_connect_probe_error_count`
  - `client_report_traffic_*`
  - `node_push_traffic_*`（受 groupBy 约束时返回不可用）
- 带宽：先给字段与口径说明，后续在实时状态表完善

这样可在一个主表中兼顾业务视角与排障视角，同时避免跨粒度误聚合。

---

## 10. `groupBy` 与指标可用性矩阵（前端直用）

说明：

- `full`：可直接按当前 `groupBy` 精确聚合
- `partial`：可返回但需提示口径限制
- `unavailable_by_group`：该分组下不应返回（建议 `null`）

| 维度组合（groupBy） | avg_delay | success/failed | node_connect_error | post_connect_probe_error | client_report_traffic_* | node_push_traffic_* | bandwidth/up/down |
|---|---|---|---|---|---|---|---|
| `date/hour` | full | full | full | full | full | full | partial |
| `date/hour + node_id` | full | full | full | full | full | full | partial |
| `date/hour + node_id + node_protocol` | partial | partial | partial | partial | partial | full | partial |
| `date/hour + node_id + app_id/app_version` | full | full | full | full | full | unavailable_by_group | partial |
| `date/hour + node_id + platform` | full | full | full | full | full | unavailable_by_group | partial |
| `date/hour + node_id + client_country/client_isp` | full | full | full | full | full | unavailable_by_group | partial |
| `date/hour + node_id + app_id + platform + country + isp` | full | full | full | full | full | unavailable_by_group | partial |
| `node_name/node_host/machine_ip/machine_ip_isp`（任意与上面组合） | 继承上面同级可用性 | 继承上面同级可用性 | 继承上面同级可用性 | 继承上面同级可用性 | 继承上面同级可用性 | 继承上面同级可用性 | 继承上面同级可用性 |

补充规则：

1. `node_push_traffic_*` 只对“时间 + 节点(+协议)”链路稳定，出现客户端维度时返回 `unavailable_by_group`。
2. `node_protocol` 来自 `v2_server.type`，而性能聚合原表未携带该列；当按协议维度聚合时，属于 join 后再聚合，标记为 `partial`。
3. `machine_ip`/`machine_ip_isp` 属于维表补充，不改变事实表粒度，仅用于展示与筛选。
4. `bandwidth/up_bandwidth/down_bandwidth` 当前无统一历史明细，统一标记 `partial`，并在接口返回 `bandwidth_source`。

建议接口返回附加字段：

```json
{
  "metric_availability": {
    "avg_delay": "full",
    "success_count": "full",
    "failed_count": "full",
    "node_connect_error_count": "full",
    "post_connect_probe_error_count": "full",
    "client_report_traffic": "full",
    "node_push_traffic": "unavailable_by_group",
    "bandwidth": "partial"
  },
  "bandwidth_source": "metrics_cache|machine_config|ip_pool_config"
}
```
