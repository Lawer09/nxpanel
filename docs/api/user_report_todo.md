# user_report 实施补充清单

## 1) 开关与配置
- [ ] `.env` 增加并确认 `USER_REPORT_ENABLED=true/false`。
- [ ] 预发环境先开启，生产灰度后全量。

## 2) 迁移与表结构
- [ ] 执行迁移：`2026_05_07_200000_create_v3_user_report_tables.php`。
- [ ] 确认 4 张表索引生效：
  - `v3_user_report_summary`
  - `v3_user_report_node`
  - `v3_user_report_user`
  - `v3_user_report_node_fail`

## 3) 上报写入链路
- [ ] `NodePerformanceService` 并行写入 `user_report:raw:{bucket}`。
- [ ] 核对补充字段：`user_id`、`report_at`、`received_at`。
- [ ] 核对 UTC+8 五分钟分桶、TTL=3600 秒。

## 4) 聚合任务
- [ ] 定时任务：`user_report:aggregate` 每 5 分钟执行。
- [ ] 聚合顺序确认：先 OSS 归档，再写统计。
- [ ] 异常重试策略确认：归档失败不删桶，不写统计。

## 5) 回放能力
- [ ] 命令可用：`user_report:replay-oss {date}`。
- [ ] 按 `--hour/--minute/--bucket` 精确回放验证。
- [ ] `--clear-day` 与 `--dry-run` 行为验证。

## 6) 查询接口（ReportController）
- [ ] `POST /report/userReport/summary/query`（无缓存）。
- [ ] `POST /report/userReport/nodeSummary/query`（有缓存）。
- [ ] `POST /report/userReport/traffic/query`（有缓存）。
- [ ] `POST /report/userReport/nodeFail/query`（有缓存）。
- [ ] 4 个接口 `groupBy` 与分页行为验证。

## 7) 缓存策略
- [ ] 当前 4 个 user_report 查询接口均不使用缓存（直查 DB）。

## 8) 数据口径校对
- [ ] `reports + vpn_connection` 合并口径核验。
- [ ] `vpn_status=2 => delay=6000`，其他成功 `delay=200` 核验。
- [ ] `probe_stage` 归一（`tunnel_establish -> node_connect`）核验。
- [ ] `node_fail` 7天清理策略核验。

## 9) 观测与告警
- [ ] 监控：每桶 payload 数、归档成功率、聚合耗时、失败数。
- [ ] 告警：连续 N 次聚合失败、桶积压超阈值。

## 10) 发布与回滚
- [ ] 发布顺序：迁移 -> 代码 -> 开关灰度。
- [ ] 回滚策略：关闭 `USER_REPORT_ENABLED`，保留既有统计查询。
