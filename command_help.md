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

# 回放某小时
php artisan user_report:replay-oss 2026-05-01 --hour=10 --clear-day

# 回放单个桶
php artisan user_report:replay-oss 2026-05-01 --bucket=202605011005

# 预览
php artisan user_report:replay-oss 2026-05-01 --dry-run
```

**流程：** 读 OSS NDJSON → 按 `report_at_ms` 还原到 Redis bucket → 调 `user_report:aggregate --skip-archive` → 聚合写表

**`--clear-day`：** 清空 `v3_user_report_node` 当日数据后再回放（用于 app_id/app_version 存量拆分）。

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

# 回放某小时+分钟
php artisan node_server_report:replay-oss 2026-05-01 --hour=10 --minute=05 --clear-day

# 回放单个桶
php artisan node_server_report:replay-oss 2026-05-01 --bucket=202605011005

# 预览
php artisan node_server_report:replay-oss 2026-05-01 --dry-run
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

# 重建某小时
php artisan report_hourly:rebuild 2026-05-09 --hour=10

# 不删已有数据，仅 upsert
php artisan report_hourly:rebuild 2026-05-09 --keep-existing
```

---

## 4. 调度配置

`app/Console/Kernel.php` 中已配置的计划任务：

| 命令 | 频率 | 说明 |
|---|---|---|
| `report_hourly:aggregate` | 每 5 分钟 | 小时表实时聚合 |
| `user_report:aggregate` | 每 5 分钟 | 用户上报实时聚合 |
| `node_server_report:dispatch` | 每 5 分钟 | 节点上报实时派发 |

---

## 5. 存量重建示例

```bash
# 1. 迁移
php artisan migrate

# 2. 回放 node 端（拆分 app_id/app_version）
php artisan node_server_report:replay-oss 2026-05-01 --clear-day

# 3. 回放 user 端（拆分 app_id/app_version）
php artisan user_report:replay-oss 2026-05-01 --clear-day

# 4. 重建小时表
php artisan report_hourly:rebuild 2026-05-01
```
