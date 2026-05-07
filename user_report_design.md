# 用户上报数据新处理链路设计（与现有处理并行）

## 1. 目标与约束

- 在**不干扰现有处理方式**前提下，新增一套用户上报处理链路。
- 上报格式基于 `user_report.md` 的“一、用户上报格式”。
- 每次上报进入系统后，先写 Redis 原始桶，且每条记录补充：
  - `user_id`
  - `report_at`（毫秒时间戳，优先 `metadata.timestamp`）
- 分桶规则：按 **UTC+8** 对齐，**5 分钟桶**。
- Redis 桶 TTL：**1 小时**。
- 定时处理：每次处理**前 5 分钟**的数据；先归档原始数据到 OSS，再做统计。
- 统计目标表：参照 `user_report.md` 第二章 4 张统计表。

---

## 2. 并行架构（不影响原链路）

### 2.1 隔离策略

- 新增独立 Redis key 前缀：`user_report:*`。
- 新增独立聚合命令：`user_report:aggregate`。
- 新增独立 DB 表：
  - `v3_user_report_summary`
  - `v3_user_report_node_summary`
  - `v3_user_report_traffic`
  - `v3_user_report_node_fail`
- 新链路开关（建议）：`USER_REPORT_ENABLED=true`，用于灰度和快速回滚。

### 2.2 数据流

1. API 收到 `batchReport` 请求并完成基础校验。
2. 标准化 payload，补充 `user_id/report_at/received_at`。
3. 写入 UTC+8 的 5 分钟 Redis 桶（TTL=3600s）。
4. 定时任务读取“前 5 分钟桶”数据。
5. 先写 OSS 原始归档（NDJSON/GZIP）。
6. 再按统计口径聚合并写入 4 张统计表（upsert/幂等）。
7. 清理 `v3_user_report_node_fail` 超过 7 天的数据。

---

## 3. 接入层与原始数据模型

## 3.1 上报标准化

- 保持原请求字段不变，仅做补充和归一：
  - `user_id`: 来自登录态。
  - `report_at`: `metadata.timestamp`（秒补齐到毫秒；异常则回退服务端当前毫秒）。
  - `received_at`: 服务端接收时间（毫秒）。
  - `bucket_at_utc8`: `report_at` 对齐到 UTC+8 的 5 分钟桶起始时间。
- `reports` 与 `user_default(type=vpn_connection)` 视为同级节点事件源。

### 3.2 Redis 分桶与 key 设计

- 桶 key：`user_report:raw:{yyyyMMddHHmm}`（UTC+8，分钟固定为 00/05/10...55）。
- value：JSON 字符串，建议一条上报一个 list 元素（`RPUSH`）。
- TTL：`EXPIRE 3600`（每次写入时刷新即可）。
- 推荐 pipeline：`RPUSH + EXPIRE`，降低 RTT。

示例记录（逻辑字段）：

```json
{
  "user_id": 12345,
  "report_at": 1746608705123,
  "received_at": 1746608706133,
  "metadata": {"app_id": "com.demo.app", "country": "US", "timestamp": 1746608705123},
  "reports": [...],
  "user_default": {...},
  "client_ip": "1.2.3.4"
}
```

---

## 4. 定时处理流程（前 5 分钟桶）

### 4.1 调度与并发控制

- 调度频率：每 5 分钟一次。
- 目标桶：`floor(now_utc8, 5m) - 5m`。
- 分布式锁：`user_report:agg:lock:{bucket}`，防止重复消费。

### 4.2 处理顺序

1. 从桶中读取原始 payload（批量）。
2. **先归档 OSS 原始数据**。
3. 归档成功后进行统计计算并写库。
4. 记录处理审计（行数、耗时、桶号、错误数）。

> 若“先归档”失败：建议本轮不入统计表，避免出现“统计有了但原始不可追溯”的不一致。

### 4.3 OSS 归档规范

- 路径建议：
  - `user_report/raw/YYYY/MM/DD/HH-mm-ss_{rand}.ndjson`
- 内容：原始 payload（补充字段后）逐行 NDJSON。
- 附加 manifest（可选）：记录 `row_count/md5/created_at`，方便回放和审计。

---

## 5. 统计口径设计（章节2对齐）

## 5.1 公共口径

- 时间维度 `date/hour`：均来自 `metadata.timestamp`，转换到 UTC+8。
- `probe_stage` 归一：`tunnel_establish -> node_connect`。
- 节点定位：
  - 优先 `node_id`
  - 缺失时根据 `node_host(vpn_node_ip)` 映射节点 ID
  - 未命中则 `node_id=0`
- `user_default(type=vpn_connection)` 默认 `probe_stage=post_connect_probe`。

### 5.2 `v3_user_report_summary`（上报次数）

- 维度：`user_id, app_id, app_version, country, date, hour`
- 指标：`report_count`
- 口径：每个 payload 记 1 次（不是 `reports` 条数）。
- 唯一键建议：`(date, hour, user_id, app_id, app_version, country)`

### 5.3 `v3_user_report_node_summary`（节点维度）

- 维度：`date, hour, node_id, node_host, node_type, probe_stage`
- 指标：
  - `avg_delay`
  - `traffic_usage`
  - `traffic_use_time`
  - `compute_count`
- 合并规则：
  - 来源 = `reports + user_default(vpn_connection)`
  - `vpn_connection` 延迟：
    - `vpn_status=2` 失败 -> `delay=6000`
    - 成功 -> `delay=200`
  - `reports` 的 `traffic_usage/traffic_use_time` 默认 0

### 5.4 `v3_user_report_traffic`（用户流量）

- 维度：`date, hour, user_id, app_id, app_version, country`
- 指标：`traffic_usage, traffic_use_time, compute_count`
- 口径：
  - 流量/时长主要来自 `vpn_connection`
  - `reports` 默认为 0（仅参与总量计数时可配置）

### 5.5 `v3_user_report_node_fail`（失败排查，保留 7 天）

- 字段：`node_id, node_host, node_type, probe_stage, error_code`
- 数据来源：
  - `reports.error_code`
  - `vpn_connection.vpn_error_msg`
- 建议补充审计字段：`date, hour, report_at, user_id, app_id, country`
- 保留策略：按 `report_at`/`created_at` 定时删除 >7 天数据。

---

## 6. 表结构与索引建议

### 6.1 索引原则

- 所有统计表必须有“时间 + 主要维度”复合索引。
- 采用 `updateOrInsert`/`INSERT ... ON DUPLICATE KEY UPDATE` 保障幂等。
- 大表建议按 `date` 分区（按月）降低历史扫描成本。

### 6.2 关键索引（建议）

- `v3_user_report_summary`
  - `UNIQUE(date, hour, user_id, app_id, app_version, country)`
  - `INDEX(user_id, date)`
- `v3_user_report_node_summary`
  - `UNIQUE(date, hour, node_id, node_host, node_type, probe_stage)`
  - `INDEX(node_id, date, hour)`
- `v3_user_report_traffic`
  - `UNIQUE(date, hour, user_id, app_id, app_version, country)`
  - `INDEX(user_id, date)`
- `v3_user_report_node_fail`
  - `INDEX(date, hour)`
  - `INDEX(node_id, date)`
  - `INDEX(error_code, date)`

---

## 7. 查询策略（当前）

- 当前阶段：`summary/nodeSummary/traffic/nodeFail` 四个查询接口均**直查 DB**，不启用缓存。
- 直查原因：便于口径联调、排查与快速修正，避免缓存带来的观测偏差。
- 性能保障：依赖统计表索引、时间范围约束与分页查询控制。

### 7.1 后续扩展（预留）

- 若后续查询压力上升，可按 Cache-Aside 增加缓存。
- 建议缓存 key：`user_report:q:{scope}:{hash}`。
- 建议版本键：`user_report:qv:{table}:{date}:{hour}`。
- 建议先对 `nodeSummary/traffic/nodeFail` 开启，`summary` 继续直查。

---

## 8. 幂等、容错与回放

- 幂等：统计表统一使用唯一键 upsert，重复处理同桶不会累计错误。
- 失败重试：
  - 归档失败 -> 不入统计，任务标记失败重试。
  - 统计失败 -> 保留桶数据或转移到 retry key。
- 回放：从 OSS 指定 bucket 文件重放到聚合任务（按原 `report_at` 再计算）。
  - 已落地命令：`user_report:replay-oss {date}`
  - 支持按 `--hour` / `--minute` / `--bucket(yyyymmddHHmm)` 精确回放
  - 支持 `--clear-day` 先清理当日 4 张统计表后重建
  - 支持 `--dry-run` 只统计不写入
- 监控指标：
  - `bucket_lag_seconds`
  - `payload_count`
  - `archive_success_rate`
  - `aggregate_latency_ms`
  - `error_count_by_stage`

---

## 9. 落地步骤（建议）

1. 新增 user_report Redis key 与写入逻辑（不改旧 key）。
2. 新建 4 张统计表与索引。
3. 实现 `user_report:aggregate`（先 OSS 后聚合）。
4. 接入查询接口（当前直查 DB，无缓存）。
5. 灰度开启 `USER_REPORT_ENABLED`，双写观察。
6. 对账（新链路与旧链路总量/趋势），通过后扩大流量。

---

## 10. 关键口径确认点（开发前固定）

- `user_default.type` 是否兼容 `vpn_connection` 与 `vpn_connect` 两种值（建议都兼容）。
- `vpn_user_traffic` 单位解析规则（MB/GB/KB/B）是否按 1024 进制。
- `country` 缺失时是否允许归类为 `ZZ` 或 `unknown`。
- `node_fail` 是否需要保留原始 `error_code` 大小写（建议保留原值，额外存 normalized 列）。
