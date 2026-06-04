# 版本日志

## 2026-06-04 自动化 webhook 请求方法修复

- 日期：2026-06-04
- 变更摘要：修复自动化通知 webhook 动作未透传 method 配置的问题，SendWebhookJob 现支持按规则配置使用 POST、PUT、PATCH 发送请求，同时补充对应回归测试。
- 影响范围：app/Services/Automation/AutomationActionDispatcher.php、app/Jobs/SendWebhookJob.php、tests/Feature/SendWebhookJobTest.php
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复上述文件到修复前版本即可，数据库与配置无需回滚。

## 2026-06-04 send_webhook 队列诊断接口

- 日期：2026-06-04
- 变更摘要：新增管理端接口 GET /api/v2/{secure_path}/system/getSendWebhookTasks，专门排查 send_webhook 队列的 pending、delayed、reserved 和 failed 状态，并新增队列任务接口文档 docs/api/queue_task_api.md。
- 影响范围：app/Http/Controllers/V2/Admin/SystemController.php、app/Http/Routes/V2/AdminRoute.php、app/Http/Requests/Admin/SendWebhookTaskIndexRequest.php、docs/api/queue_task_api.md
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：删除新增路由、请求类和文档，并移除 SystemController 中对应诊断方法即可回滚。

## 2026-06-04 WooCommerce 订单状态短路修复

- 日期：2026-06-04
- 变更摘要：修复 `OrderService::paid()` 对订单待支付状态的严格比较短路问题，避免 WooCommerce 回执已标记 `processed` 但本地订单仍停留在 `0`。
- 影响范围：`app/Models/Order.php`、`app/Services/OrderService.php`、`tests/Feature/WooCommerceOrderPaidTest.php`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复上述文件到修复前版本即可，数据库数据无需回滚。
## 2026-06-04 飞书 webhook 发送格式修复

- 日期：2026-06-04
- 变更摘要：修复自动化 webhook 对飞书机器人发送单条消息时请求体结构不兼容的问题，并调整飞书签名写入方式为请求体 timestamp/sign；同时补充对应回归测试和自动化 webhook 文档说明。
- 影响范围：app/Jobs/SendWebhookJob.php、tests/Feature/SendWebhookJobTest.php、docs/api/automation_rules_api.md
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复上述文件到修复前版本即可，数据库与配置无需回滚。

## 2026-06-04 webhook 合并消息格式调整

- 日期：2026-06-04
- 变更摘要：调整自动化 webhook 的多条消息合并格式，改为按每条 message 直接换行拼接，不再附加序号、统计头和事件标签。
- 影响范围：app/Jobs/SendWebhookJob.php、docs/api/automation_rules_api.md
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复上述文件到调整前版本即可，数据库与配置无需回滚。
## 2026-06-04 webhook 空消息兜底修复

- 日期：2026-06-04
- 变更摘要：修复自动化 webhook 在自定义模板缺少上下文变量时可能渲染为空消息的问题；现统一注入通用目标变量，并在自定义模板渲染为空时自动回退到默认模板。
- 影响范围：app/Services/Automation/AutomationActionDispatcher.php、docs/api/automation_rules_api.md
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复上述文件到修复前版本即可，数据库与配置无需回滚。
## 2026-06-04 流量平台统计数据源切换

- 日期：2026-06-04
- 变更摘要：项目日聚合的流量数据源切换到 `traffic_platform_usage_daily`；流量平台展示接口按维度分别切换到 `traffic_platform_usage_hourly` 和 `traffic_platform_usage_daily`，并统一返回新字段，不再继续返回旧 `stat*` 字段。
- 影响范围：`app/Console/Commands/AggregateProjectDailyData.php`、`app/Services/TrafficPlatform/TrafficPlatformUsageService.php`、`app/Models/TrafficPlatformUsageDaily.php`、`app/Models/TrafficPlatformUsageHourly.php`、`docs/api/traffic_platform_platforms_api.md`、`version.md`
- 是否需要迁移：否，本次仅切换读取新表，未新增数据库结构变更。
- 回滚说明：恢复上述文件到切换前版本，并将项目聚合和流量平台展示接口的数据源切回旧表即可回滚。

## 2026-06-04 流量平台自动化小时口径切换

- 日期：2026-06-04
- 变更摘要：`TrafficPlatformAutomationService` 的小时相关指标从旧表 `traffic_platform_usage_stat` 切换到 `traffic_platform_usage_hourly`；`usage_1h_mb` 按当前小时桶汇总，`usage_6h_mb` 按当前小时起向前连续 6 个小时桶汇总，`avg_hourly_usage_mb` 与 `eta_hours` 延续原计算方式。
- 影响范围：`app/Services/Automation/TrafficPlatformAutomationService.php`、`docs/api/automation_rules_api.md`、`version.md`
- 是否需要迁移：否，本次仅切换自动化规则读取的新表，未新增数据库结构变更。
- 回滚说明：恢复上述文件到切换前版本，并将自动化小时指标的数据源切回旧表即可回滚。

## 2026-06-04 基线 migration 修正与项目小时广告自动化新增

- 日期：2026-06-04
- 变更摘要：直接修正原始 migration，使新环境基线直接创建 `traffic_platform_usage_hourly`、`traffic_platform_usage_daily` 和 `ad_revenue_hourly`；同时新增项目级小时广告自动化模块 `project_ad_revenue_hourly`，按上一完整小时监控项目广告收入，并通过 `project_ad_platform_accounts` 按账号映射到 `project_code`，不再依赖废弃的 `ad_revenue_hourly.project_id`。
- 影响范围：`database/migrations/2026_04_28_000001_create_traffic_platform_tables.php`、`database/migrations/2026_04_23_000001_create_ad_platform_tables.php`、`app/Services/Automation/ProjectAdRevenueHourlyAutomationService.php`、`app/Providers/AutomationServiceProvider.php`、`app/Console/Kernel.php`、`docs/api/automation_rules_api.md`、`docs/components/automation_rule_development_guide.md`、`version.md`
- 是否需要迁移：否，本次未新增迁移文件；仅修正原始 migration 基线定义并新增自动化模块代码。
- 回滚说明：恢复上述文件到变更前版本，并移除 `project_ad_revenue_hourly` 模块的注册、调度和文档说明即可回滚。

