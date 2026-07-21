# 报表系统命令手册

## 1. 用户上报命令

### 1.1 user_report:aggregate

聚合用户上报数据（先 OSS 归档，再统计写库）。

```bash
# 默认聚合上一 5 分钟桶
php artisan user_report:aggregate

# 指定桶（yyyyMMddHHmm, UTC+8）
php artisan user_report:aggregate --bucket=202605111005

# 跳过 OSS 归档（用于回放重算）
php artisan user_report:aggregate --bucket=202605111005 --skip-archive
```

**写入表：** `v3_user_report_summary` / `v3_user_report_node` / `v3_user_report_user` / `v3_user_report_node_fail`

### 1.2 user_report:replay-oss

从 OSS NDJSON 归档回放用户上报数据，重新聚合到各统计表。

```bash
# 回放某天全部
php artisan user_report:replay-oss 2026-05-01 --clear-day

# 回放日期范围
php artisan user_report:replay-oss 2026-05-01 --to=2026-05-05 --clear-day

# 回放某小时
php artisan user_report:replay-oss 2026-05-01 --hour=10 --clear-day

# 回放单个桶
php artisan user_report:replay-oss 2026-05-01 --bucket=202605011005

# 预览
php artisan user_report:replay-oss 2026-05-01 --dry-run
```

**流程：** 读 OSS NDJSON → 按 metadata.timestamp 还原到 Redis bucket → 调 `user_report:aggregate --skip-archive` → 聚合写表

**`--to=YYYY-MM-DD`：** 结束日期，不传则只处理 `{date}` 单天。
**`--clear-day`：** 清空 `v3_user_report_node`、`v3_user_report_user`、`v3_user_report_summary`、`v3_user_report_node_fail` 当日数据后再回放（避免 replay 累加导致翻倍）。

---

## 2. 节点上报命令

### 2.1 node_server_report:dispatch

派发节点上报数据（先 OSS 归档，再投递队列处理）。

```bash
# 默认处理上一 5 分钟桶
php artisan node_server_report:dispatch

# 指定桶
php artisan node_server_report:dispatch --bucket=202605111005
```

**写入表：** `v3_node_server_report_node` / `v3_node_server_report_user`

### 2.2 node_server_report:replay-oss

从 OSS NDJSON 归档回放节点上报数据，重新聚合。

```bash
# 回放某天全部
php artisan node_server_report:replay-oss 2026-05-01 --clear-day

# 回放日期范围
php artisan node_server_report:replay-oss 2026-05-01 --to=2026-05-05 --clear-day

# 回放某小时+分钟
php artisan node_server_report:replay-oss 2026-05-01 --hour=10 --minute=05 --clear-day

# 回放单个桶
php artisan node_server_report:replay-oss 2026-05-01 --bucket=202605011005

# 预览
php artisan node_server_report:replay-oss 2026-05-01 --dry-run
```

**`--to=YYYY-MM-DD`：** 结束日期，不传则只处理 `{date}` 单天。

### 2.3 node_server_report:cleanup-archive

清理 OSS 归档中的重复文件（修复 `--skip-archive` 前回放产生的重复归档）。

**原理：** 对比文件 OSS `lastModified` 与 payload 内 `report_at_ms`。若归档时间 - 数据时间 > threshold（默认 30 分钟），则判定为回放产物。

```bash
# 默认 30 分钟阈值预览
php artisan node_server_report:cleanup-archive --from=2026-05-12 --to=2026-05-13 --dry-run

# 调整阈值（归档比数据晚超过 60 分钟才算重复）
php artisan node_server_report:cleanup-archive --from=2026-05-12 --threshold=60 --dry-run

# 确认删除
php artisan node_server_report:cleanup-archive --from=2026-05-12

# 跳过确认直接删
php artisan node_server_report:cleanup-archive --date=2026-05-12 --force
```

**安全策略：** 先 `--dry-run` 预览，确认无误后去掉 `--dry-run` 执行删除。--threshold 默认 30 分钟，可根据实际回放时间窗口调整。

---

## 2.4 user_report:cleanup-archive

与 `node_server_report:cleanup-archive` 同名，清理 `user_report/raw/` 下因回放产生的重复归档。

```bash
# 预览
php artisan user_report:cleanup-archive --from=2026-05-10 --to=2026-05-11 --dry-run

# 删除
php artisan user_report:cleanup-archive --from=2026-05-10 --to=2026-05-11 --force
```

---

## 3. 小时报表命令

### 3.1 report_hourly:aggregate

从源表聚合到小时汇总表（`v3_report_user_hourly` / `v3_report_node_hourly`）。

```bash
# 默认重算当前小时 + 上一小时
php artisan report_hourly:aggregate

# 指定小时重算
php artisan report_hourly:aggregate --date=2026-05-09 --hour=10

# 指定小时重建（先删后插）
php artisan report_hourly:aggregate --date=2026-05-09 --hour=10 --rebuild
```

**数据源：**
- `v3_report_user_hourly` ← `v3_user_report_user` + `v3_node_server_report_user`
- `v3_report_node_hourly` ← `v3_node_server_report_node` + `v3_user_report_node`

### 3.2 report_hourly:reconcile

对账：对比源表与小时表的总量，不一致时说明数据有差异。

```bash
# 对账某天
php artisan report_hourly:reconcile 2026-05-09

# 对账某小时
php artisan report_hourly:reconcile 2026-05-09 --hour=10
```

**对账口径：**
- `user.report_count_user` vs `user_hourly.report_count_user`
- `user.report_count_node` vs `user_hourly.report_count_node`
- `node.report_count_user` vs `node_hourly.report_count_user`
- `node.report_count_node` vs `node_hourly.report_count_node`

### 3.3 report_hourly:rebuild

天级/小时级重建小时汇总表（内部循环调 `report_hourly:aggregate`）。

```bash
# 重建整天
php artisan report_hourly:rebuild 2026-05-09

# 重建日期范围
php artisan report_hourly:rebuild 2026-05-09 --to=2026-05-11

# 重建某小时
php artisan report_hourly:rebuild 2026-05-09 --hour=10

# 不删已有数据，仅 upsert
php artisan report_hourly:rebuild 2026-05-09 --keep-existing
```

**`--to=YYYY-MM-DD`：** 结束日期，不传则只处理 `{date}` 单天。

---

## 4. 调度配置

`app/Console/Kernel.php` 中已配置的计划任务：

| 命令 | 频率 | 说明 |
|---|---|---|
| `report_hourly:aggregate` | 每 5 分钟 | 小时表实时聚合 |
| `user_report:aggregate` | 每 5 分钟 | 用户上报实时聚合 |
| `node_server_report:dispatch` | 每 5 分钟 | 节点上报实时派发 |
| `firebase_report:aggregate --hours=72` | 每 5 分钟 | Firebase 事件滚动 3 天聚合 |

---

## 4.1 Firebase 统计命令

### firebase_report:aggregate

基于 `firebase_event_common` 与 `firebase_event_vpn_session` 聚合写入：

- `firebase_device_first_seen`
- `firebase_report_user_summary`
- `firebase_report_node`

```bash
# 默认重算最近72小时
php artisan firebase_report:aggregate

# 指定滚动窗口
php artisan firebase_report:aggregate --hours=24

# 首次全量重建 device 首见表
php artisan firebase_report:aggregate --hours=72 --rebuild-first-seen
```

说明：
- `new_user_count` 口径为「按 device_id 首次出现」
- 默认每次仅更新窗口内数据，`--rebuild-first-seen` 用于首见表初始化/纠偏

---

## 5. 存量重建示例

```bash
# 1. 迁移
php artisan migrate

# 2. 回放 node 端（拆分 app_id/app_version）
php artisan node_server_report:replay-oss 2026-05-01 --to=2026-05-11 --clear-day

# 3. 回放 user 端（拆分 app_id/app_version）
php artisan user_report:replay-oss 2026-05-01 --to=2026-05-11 --clear-day

# 4. 重建小时表
php artisan report_hourly:rebuild 2026-05-01 --to=2026-05-11
```
---

## 6. 套餐到期降级命令

### 6.1 subscription:downgrade-expired-to-free

将已过期用户自动降级到默认免费套餐。

免费套餐查找优先级：

- `plan_id = 1`
- 套餐名精确等于 `Free`
- 套餐名精确等于 `免费`

如果以上都不存在，则命令只记录 warning，不修改任何用户，保持系统当前逻辑不变。

降级时会同步免费套餐属性，并重置用户流量：

- `plan_id`
- `group_id`
- `transfer_enable`
- `speed_limit`
- `device_limit`
- `expired_at = null`
- `u = 0`
- `d = 0`

```bash
# 默认每批处理 100 个用户
php artisan subscription:downgrade-expired-to-free

# 指定批大小
php artisan subscription:downgrade-expired-to-free --chunk=200
```

调度说明：

- 已在 `app/Console/Kernel.php` 中配置为每分钟执行一次
- 使用 `onOneServer()` 和 `withoutOverlapping()` 防止重复调度

---

## 7. 零流量无上报用户禁用命令

### 7.1 user:ban-inactive-zero-usage

每日检查并禁用满足以下条件的用户：

- 注册日期为运行当天往前第 `--days + 1` 天，默认仅判断注册第 8 天的用户
- 套餐为 Free（按 `plan_id = 1`、套餐名 `Free/free/免费` 识别）
- `v2_user.u + v2_user.d = 0`
- 最近 `--days` 天的 `v3_report_user_hourly` 中没有用户上报流量、节点上报流量或上报数
- 当前未被封禁

```bash
# 默认检查 7 天窗口，每批处理 100 个用户
php artisan user:ban-inactive-zero-usage

# 指定检查窗口和批大小
php artisan user:ban-inactive-zero-usage --days=7 --chunk=200
```

调度说明：
- 已在 `app/Console/Kernel.php` 中配置为每日 `01:30` 执行
- 使用 `onOneServer()` 和 `withoutOverlapping()` 防止重复调度
---

## 8. 项目昨日流量飞书日报命令

### 8.1 project:send-yesterday-traffic-report

每天汇总 active 项目的昨日流量使用量，并通过飞书机器人 webhook 发送文本日报。

配置项：

```env
FEISHU_PROJECT_TRAFFIC_REPORT_WEBHOOK_URL=https://open.feishu.cn/open-apis/bot/v2/hook/your-webhook-token
FEISHU_PROJECT_TRAFFIC_REPORT_TIMEOUT_SECONDS=10
```

统计口径：

- 项目范围：`project_projects.status = active`。
- 绑定过滤：仅保留 `project_traffic_platform_accounts.enabled = 1` 且已绑定代理流量账户的项目；绑定关系单独查询后在 PHP 中过滤，不通过 SQL 联表处理。
- 数据来源：`project_daily_aggregates.traffic_usage_mb`。
- 日期口径：默认应用时区 `Asia/Shanghai` 的昨日自然日。
- 聚合方式：按 `project_code` 汇总 `SUM(traffic_usage_mb)`。
- 展示单位：`traffic_usage_mb / 1024`，保留 2 位小数，单位 GB。
- 明细格式：按 `department` 分组展示；项目行展示项目名称、项目代号、负责人和 GB 值，仅展示字段值。
- 无昨日聚合数据的 active 项目会展示 `0.00 GB`。

```bash
# 默认发送昨日项目流量日报
php artisan project:send-yesterday-traffic-report

# 指定日期补发
php artisan project:send-yesterday-traffic-report --date=2026-06-29
```

调度说明：

- 已在 `app/Console/Kernel.php` 配置为每日 `09:30` 执行。
- 使用 `onOneServer()` 和 `withoutOverlapping(10)` 防止多实例重复发送。

回滚说明：

- 移除 `project:send-yesterday-traffic-report` 命令、Kernel 调度、飞书配置项和文档即可。
- 无数据库结构变更，不需要执行 migration 回滚。

---

### 8.2 project:check-store-online-status

每 30 分钟检测一次未上线项目的商店页链接，商店页可访问时自动更新投放状态。

```bash
# 默认最多检测 200 个未上线项目
php artisan project:check-store-online-status

# 指定单次检测上限
php artisan project:check-store-online-status --limit=100
```

检测口径：

- 项目范围：`project_projects.ad_status = 未上线` 且 `store_page_url` 非空。
- 请求方式：使用 `GET` 访问商店页链接，超时时间 10 秒。
- 上线判定：仅最终响应状态码等于 `200` 时，将 `ad_status` 更新为 `白包在线`。
- 失败处理：非 200 或请求异常不修改状态；异常会写入 warning 日志并继续检测后续项目。

调度说明：

- 已在 `app/Console/Kernel.php` 配置为每 30 分钟执行。
- 使用 `onOneServer()` 和 `withoutOverlapping(25)` 防止重复调度。

回滚说明：

- 移除 `project:check-store-online-status` 命令、Kernel 调度和文档即可。
- 无数据库结构变更，不需要执行 migration 回滚。

---

## 9. AID channel_type 异步刷新命令

### 9.1 aid-channel-type:flush

将 `loginByAid` 产生的待更新 `channel_type` 队列批量写回 `v2_user.register_metadata.channel_type`。

```bash
# 默认最多处理 1000 条
php artisan aid-channel-type:flush

# 指定单次处理上限
php artisan aid-channel-type:flush --limit=500
```

说明：
- 仅处理 `aid_channel_type_update_queues` 队列中的记录。
- 只更新 `register_metadata.channel_type` 一个字段，不修改其他 metadata。
- 成功后删除队列记录；失败记录会保留并增加 `attempts`，写入 `error_message`。
- 已在 `app/Console/Kernel.php` 配置为每 5 分钟执行一次，使用 `onOneServer()` 和 `withoutOverlapping(4)` 防止重复调度。
