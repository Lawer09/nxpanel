# v3_user_report_count 表缺失修复记录

## 现象

- 时间：2026-07-28 15:00:09 起。
- `perf:aggregate` 写入 `v3_user_report_count` 时报错：`Base table or view not found: 1146 Table 'nxpanel.v3_user_report_count' doesn't exist`。
- `project:aggregate-daily` 随后查询同一表失败，项目日报聚合被阻断。

## 原因

- 线上 `migrations` 表中 `2026_04_19_100000_create_user_report_count_table` 已标记执行，但实际主表缺失。
- 时间线显示 2026-07-28 14:55:09 `perf:aggregate` 仍成功，15:00:09 首次缺表失败。
- 仓库测试中存在直接 `Schema::dropIfExists('v3_user_report_count')` 的用例；在 production 配置下误跑测试会删除生产主表。

## 修复

- 已先在线上补建 `v3_user_report_count`，并补齐 2026-07-07 新增的查询索引。
- 新增 repair migration：`2026_07_28_152000_repair_v3_user_report_count_table.php`，当迁移记录和真实 schema 漂移时自动补建主表、补字段、补索引。
- `tests/TestCase.php` 增加保护，拒绝在 `APP_ENV=production` 或非 `sqlite :memory:` 数据库下运行测试。

## 后续

- 需要从 OSS raw payload 回放恢复缺失期间和历史 `v3_user_report_count` 数据。
- 生产服务器不再直接执行 PHPUnit；如需验证，必须使用独立测试库或本地 sqlite 内存库。
