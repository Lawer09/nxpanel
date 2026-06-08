# 版本日志

- 说明，不可修改本说明，并严格遵守如下条件
- 1. 日志内容只追加，不修改

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

## 2026-06-04 项目小时广告自动化指标收敛

- 日期：2026-06-04
- 变更摘要：收窄 `project_ad_revenue_hourly` 模块的条件指标范围，移除 `project_code`、`project_name`、`report_hour`、`has_data` 等非统计字段，仅保留统计字段用于规则条件判断；默认通知模板同步移除上报小时和是否有数据字段。
- 影响范围：`app/Services/Automation/ProjectAdRevenueHourlyAutomationService.php`、`docs/api/automation_rules_api.md`、`docs/components/automation_rule_development_guide.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复上述文件到本次调整前版本即可，数据库无需回滚。

## 2026-06-05 报表查询 Service 下沉与项目日报汇总

- 日期：2026-06-05
- 变更摘要：将 `ReportController` 中原本直接访问数据库和缓存的报表查询逻辑下沉到 Service；新增 `ReportQueryService`；扩展 `ProjectReportService` 接管项目日报查询，并在返回结果中新增与 `page` 同级的 `summary` 汇总数据。
- 影响范围：`app/Http/Controllers/V3/Admin/ReportController.php`、`app/Services/ReportQueryService.php`、`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复 `ReportController` 旧版查询实现，移除 `ReportQueryService`，并回退项目日报响应中的 `summary` 字段即可。

## 2026-06-06 投放消耗同步链路统一修复

- 日期：2026-06-06
- 变更摘要：新增共享 `AdSpendSyncService`，将手动接口 `POST /api/v3/admin/{securePath}/ad-spend-platform/sync` 与定时命令 `ad-spend:sync --lookback-days=2` 的任务建单、拉取、项目匹配、写库和状态回写逻辑统一到同一条链路；同时为 `Octane::tick(... schedule:run)` 增加数据库事务回滚与重连防护，并补充同步回归测试与投放同步文档。
- 影响范围：`app/Services/AdSpendSyncService.php`、`app/Http/Controllers/V3/Admin/AdSpendPlatform/AdSpendPlatformController.php`、`app/Console/Commands/SyncAdSpendReports.php`、`app/Providers/OctaneServiceProvider.php`、`tests/Feature/AdSpendSyncServiceTest.php`、`tests/Feature/OctaneServiceProviderTest.php`、`docs/api/ad_spend_sync_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复上述文件到修复前版本，移除共享同步 Service 和 Octane 调度前数据库重置逻辑即可回滚。

## 2026-06-06 投放同步调度时间调整

- 日期：2026-06-06
- 变更摘要：将投放消耗定时同步命令 `ad-spend:sync --lookback-days=2` 的调度时间从每小时整点调整为每小时第 `5` 分钟执行。
- 影响范围：`app/Console/Kernel.php`、`docs/api/ad_spend_sync_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：将 `app/Console/Kernel.php` 中对应调度恢复为整点执行，并同步回退文档说明即可。

## 2026-06-08 项目日报 CSV 导出

- 日期：2026-06-08
- 变更摘要：为项目日报查询新增管理端 CSV 导出接口 `POST /api/v3/{secure_path}/report/project/export`；复用原有筛选、分组、排序逻辑导出全量结果，并补充项目报表导出文档与前端调用说明。
- 影响范围：`app/Http/Controllers/V3/Admin/ReportController.php`、`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Requests/Admin/ProjectAggregateDailyExportRequest.php`、`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除管理端导出路由、导出请求类与 CSV 导出逻辑，并回退项目报表文档中的导出说明即可。

## 2026-06-08 PB 点击归因回调接口

- 日期：2026-06-08
- 变更摘要：新增公开接口 `GET /pb/com.jkcl.zwx.vpn`，用于接收 `clickid` 与 `deviceid` 并落库，按 `clickid` 做唯一去重。
- 影响范围：`routes/web.php`、`app/Http/Controllers/Postback/PostbackController.php`、`app/Http/Requests/Postback/PostbackStoreRequest.php`、`app/Services/PostbackReceiptService.php`、`app/Models/PostbackReceipt.php`、`database/migrations/2026_06_08_120000_create_postback_receipts_table.php`、`tests/Feature/PostbackStoreTest.php`、`docs/api/postback_api.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_08_120000_create_postback_receipts_table.php`。
- 回滚说明：回滚该迁移并删除对应路由、控制器、请求类、服务、模型、测试和文档即可。

