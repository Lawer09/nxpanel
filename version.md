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

## 2026-06-08 PB 点击归因回调路径调整

- 日期：2026-06-08
- 变更摘要：将公开 PB 回调路径从 `GET /pb/com.jkcl.zwx.vpn` 调整为无认证的 `GET /api/v3/pb/{package_name}`，避免未命中的 `/pb/*` 请求落到前端页面；同时将幂等规则调整为按 `package_name + clickid` 去重。
- 影响范围：`app/Http/Routes/V3/PbRoute.php`、`routes/web.php`、`app/Http/Controllers/Postback/PostbackController.php`、`app/Services/PostbackReceiptService.php`、`app/Models/PostbackReceipt.php`、`database/migrations/2026_06_08_130000_update_postback_receipts_unique_index.php`、`tests/Feature/PostbackStoreTest.php`、`docs/api/postback_api.md`、`version.md`
- 是否需要迁移：是，除创建表外，还需执行新增迁移 `2026_06_08_130000_update_postback_receipts_unique_index.php`。
- 回滚说明：删除 V3 PB 路由，恢复旧的 `web.php` 路由，并回滚新增索引迁移即可。

## 2026-06-09 到期套餐自动降级到免费套餐

- 日期：2026-06-09
- 变更摘要：新增 `subscription:downgrade-expired-to-free` 定时命令，按 `plan_id=1`、套餐名 `Free`、套餐名 `免费` 的优先级解析默认免费套餐，并将已过期用户自动降级到该套餐；降级后同步免费套餐属性并重置流量。
- 影响范围：`app/Services/ExpiredPlanDowngradeService.php`、`app/Console/Commands/DowngradeExpiredUsersToFreePlan.php`、`app/Services/TrafficResetService.php`、`app/Console/Kernel.php`、`tests/Feature/ExpiredPlanDowngradeCommandTest.php`、`docs/command_help.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：删除降级服务、命令、调度和测试，并回退命令文档与本条版本记录即可。

## 2026-06-14 AID 注册 IP 封禁与管理端批量封禁

- 日期：2026-06-14
- 变更摘要：`loginByAid` 支持保存 `metadata.ip`，新增 `blocked_user_ips` 封禁 IP 表；管理端新增 `POST /api/v3/{secure_path}/user/batchBan` 批量封禁用户并记录注册 IP，用户列表新增 `onlyBanned` 查询参数；AID 新注册用户如果 IP 命中封禁列表会立即封禁。
- 影响范围：`app/Services/Auth/LoginService.php`、`app/Services/BlockedUserIpService.php`、`app/Models/BlockedUserIp.php`、`app/Http/Controllers/V1/Passport/AuthController.php`、`app/Http/Controllers/V3/Passport/AuthController.php`、`app/Http/Controllers/V3/Admin/UserController.php`、`app/Http/Requests/Admin/UserBatchBanRequest.php`、`app/Http/Routes/V3/AdminRoute.php`、`database/migrations/2026_06_14_120000_create_blocked_user_ips_table.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：是，需执行 `2026_06_14_120000_create_blocked_user_ips_table.php`。
- 回滚说明：回滚新增迁移并移除封禁 IP 服务、模型、管理端接口、AID 注册封禁检查、测试和文档说明即可。

## 2026-06-11 V3 用户接口认证参数兼容

- 日期：2026-06-11
- 变更摘要：调整 `user` 中间件，在保留 Sanctum `Authorization` 请求头认证的基础上，兼容通过 `auth_data` 或 `authorization` 请求参数传递用户认证 token，并支持 `Bearer xxxxxx` 与裸 token 两种格式。
- 影响范围：`app/Http/Middleware/User.php`、`tests/Feature/UserAuthParameterCompatibilityTest.php`、`docs/api/client_user_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复 `User` 中间件为仅依赖 Sanctum Header 认证，并删除对应测试和文档说明即可。

## 2026-06-14 管理端用户注册日期范围查询

- 日期：2026-06-14
- 变更摘要：管理端用户列表查询支持 `createdAtFrom` 与 `createdAtTo` 参数，按 `v2_user.created_at` 注册时间戳进行包含边界的范围过滤；日期字符串会转换为 Unix 时间戳，结束日期仅传 `YYYY-MM-DD` 时按当天 `23:59:59` 处理。
- 影响范围：`app/Http/Controllers/V3/Admin/UserController.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除用户列表注册时间范围过滤逻辑、对应测试与文档说明即可。

## 2026-06-14 管理端封禁用户 IP 列表查询与删除

- 日期：2026-06-14
- 变更摘要：管理端新增 `POST /api/v3/{secure_path}/user/blockedIp/fetch` 封禁用户 IP 列表查询接口和 `POST /api/v3/{secure_path}/user/blockedIp/delete` 删除接口，用于查询和解除 `blocked_user_ips` 中的封禁 IP 记录。
- 影响范围：`app/Http/Controllers/V3/Admin/UserController.php`、`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Requests/Admin/BlockedUserIpFetchRequest.php`、`app/Http/Requests/Admin/BlockedUserIpDeleteRequest.php`、`app/Services/BlockedUserIpService.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除封禁 IP 列表查询/删除接口、对应请求校验、服务方法、测试和文档说明即可。

## 2026-06-14 loginByAid 返回增加 is_ban

- 日期：2026-06-14
- 变更摘要：`POST /api/v1/passport/auth/loginByAid` 与 `POST /api/v3/passport/auth/loginByAid` 的成功返回新增 `is_ban` 字段，用于显式表示当前用户是否处于封禁状态。
- 影响范围：`app/Http/Controllers/V1/Passport/AuthController.php`、`app/Http/Controllers/V3/Passport/AuthController.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 `loginByAid` 成功返回中的 `is_ban` 字段，以及对应测试和文档说明即可。

## 2026-06-14 client/sub/json 增加国家全称

- 日期：2026-06-14
- 变更摘要：`GET /api/v3/client/sub/json` 返回的每个节点新增 `country_code` 与 `country_name` 字段，其中 `country_name` 为国家缩写对应的英文全称；同时保留原有按国家缩写分组的返回结构。
- 影响范围：`app/Http/Controllers/V3/Client/ClientController.php`、`tests/Feature/UserAuthParameterCompatibilityTest.php`、`docs/api/client_user_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除订阅 JSON 节点中的 `country_code` / `country_name` 字段，以及对应测试和文档说明即可。

## 2026-06-17 项目用户 App 绑定增加 app_link

- 日期：2026-06-17
- 变更摘要：为 `project_user_app_map` 增加 `app_link` 字段，支持在项目用户 App 绑定中保存 App 跳转或下载链接；同步更新管理端新增/修改接口校验、返回字段与项目接口文档。
- 影响范围：`database/migrations/2026_06_17_120000_add_app_link_to_project_user_app_map_table.php`、`app/Services/ProjectUserAppMapService.php`、`app/Http/Requests/Admin/ProjectUserAppMapStoreRequest.php`、`app/Http/Requests/Admin/ProjectUserAppMapUpdateRequest.php`、`app/Http/Resources/ProjectResource.php`、`docs/api/project_api.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_17_120000_add_app_link_to_project_user_app_map_table.php`。
- 回滚说明：回滚该迁移并删除 `app_link` 字段相关读写、返回和文档说明即可。

## 2026-06-17 项目报表投放 CPC 口径修复

- 日期：2026-06-17
- 变更摘要：修复项目报表查询与导出中投放 CPC/CPM 等字段错误使用广告收入侧点击和展示重算的问题；改为统一基于 `ad_spend_platform_daily_reports` 聚合生成 `adSpendCost`、`adSpendCpi`、`adSpendCpc`、`adSpendCpm`、`totalCost`、`profit`、`roi`，并同步修正文档口径说明。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`docs/api/project_aggregates_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复项目报表查询服务对 `project_daily_aggregates` 中投放字段的旧汇总逻辑，并回退相关文档说明即可。

## 2026-06-18 项目报表新增流量成本占比

- 日期：2026-06-18
- 变更摘要：项目日报查询、汇总与 CSV 导出新增 `trafficCostRatio` 字段，按 `trafficCost / (adSpendCost + trafficCost)` 计算流量成本占总成本比例，并支持按该字段排序。
- 影响范围：`app/Services/ProjectReportService.php`、`app/Http/Requests/Admin/ProjectAggregateDailyQueryRequest.php`、`app/Http/Requests/Admin/ProjectAggregateDailyExportRequest.php`、`docs/api/project_report_query_api.md`、`docs/api/project_aggregates_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 `trafficCostRatio` 计算、返回、排序白名单、CSV 列与文档说明即可。

## 2026-06-21 零流量无上报用户自动禁用

- 日期：2026-06-21
- 变更摘要：新增 `user:ban-inactive-zero-usage` 定时命令，每日检查注册超过 7 天、累计消耗流量为 0、最近 7 天无上报流量且无上报数的未封禁用户，并自动设置 `banned = 1`。
- 影响范围：`app/Services/InactiveZeroUsageUserBanService.php`、`app/Console/Commands/BanInactiveZeroUsageUsers.php`、`app/Console/Kernel.php`、`tests/Feature/BanInactiveZeroUsageUsersCommandTest.php`、`docs/command_help.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：删除新增服务、命令、调度和测试，并回退命令文档与本条版本记录即可。

## 2026-06-21 零流量无上报用户禁用限定 Free 套餐

- 日期：2026-06-21
- 变更摘要：调整 `user:ban-inactive-zero-usage` 筛选条件，仅禁用 Free 套餐用户；Free 套餐按 `plan_id = 1`、套餐名 `Free/free/免费` 识别，避免付费套餐用户因无流量无上报被自动禁用。
- 影响范围：`app/Services/InactiveZeroUsageUserBanService.php`、`tests/Feature/BanInactiveZeroUsageUsersCommandTest.php`、`docs/command_help.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除服务中的 Free 套餐筛选条件，并回退对应测试、文档与本条版本记录即可。

## 2026-06-21 零流量无上报用户禁用限定注册第 8 天

- 日期：2026-06-21
- 变更摘要：调整 `user:ban-inactive-zero-usage` 注册时间筛选范围，不再处理所有早于阈值日期的用户；默认仅判断注册日期为运行当天往前第 8 天的 Free 套餐用户，最近 7 天上报窗口保持不变。
- 影响范围：`app/Services/InactiveZeroUsageUserBanService.php`、`app/Console/Commands/BanInactiveZeroUsageUsers.php`、`tests/Feature/BanInactiveZeroUsageUsersCommandTest.php`、`docs/command_help.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复服务中的注册时间筛选为阈值之前用户，并回退对应测试、文档与本条版本记录即可。

## 2026-06-21 项目投放状态字段与报表筛选

- 日期：2026-06-21
- 变更摘要：`project_projects` 新增 `ad_status` 投放状态字段；项目管理接口支持 `adStatus` 读写和列表筛选；项目日报查询、项目日报 CSV 导出与项目小时报表支持通过 `filters.adStatuses` 按投放状态过滤，但报表返回字段不新增投放状态。
- 影响范围：`database/migrations/2026_06_21_120000_add_ad_status_to_project_projects_table.php`、`app/Services/ProjectService.php`、`app/Services/ProjectReportService.php`、`app/Http/Resources/ProjectResource.php`、`app/Http/Requests/Admin/ProjectFetchRequest.php`、`app/Http/Requests/Admin/ProjectSaveRequest.php`、`app/Http/Requests/Admin/ProjectUpdateRequest.php`、`app/Http/Requests/Admin/ProjectAggregateDailyQueryRequest.php`、`app/Http/Requests/Admin/ProjectAggregateDailyExportRequest.php`、`app/Http/Requests/Admin/ProjectReportHourlyQueryRequest.php`、`docs/api/project_api.md`、`docs/api/project_report_query_api.md`、`docs/api/project_report_hourly_api.md`、`docs/api/application_route_api.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_21_120000_add_ad_status_to_project_projects_table.php`。
- 回滚说明：回滚新增迁移并移除 `adStatus` 读写、返回、筛选校验和报表过滤逻辑，同时回退对应文档说明即可。

## 2026-06-22 项目表补充 Excel 元数据字段

- 日期：2026-06-22
- 变更摘要：按项目跟踪表字段为 `project_projects` 补充 Adspower 环境、开发者 Gmail、应用名称、包名、域名/协议链接、Facebook、Admob、Firebase、Yandex、商店页等项目元数据字段；项目创建/编辑接口支持对应 camelCase 输入，列表/详情返回新增字段，项目列表支持按 `packageName`、`developerGmail` 筛选。
- 影响范围：`database/migrations/2026_06_22_120000_add_excel_metadata_fields_to_project_projects_table.php`、`app/Services/ProjectService.php`、`app/Http/Resources/ProjectResource.php`、`app/Http/Requests/Admin/ProjectFetchRequest.php`、`app/Http/Requests/Admin/ProjectSaveRequest.php`、`app/Http/Requests/Admin/ProjectUpdateRequest.php`、`docs/api/project_api.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_22_120000_add_excel_metadata_fields_to_project_projects_table.php`。
- 回滚说明：回滚新增迁移并移除项目接口中的新增元数据字段校验、读写、返回和文档说明即可。

## 2026-06-22 项目报表返回项目元数据

- 日期：2026-06-22
- 变更摘要：项目日报查询在返回行包含唯一 `projectCode` 时附带项目表元数据字段，包括 `adStatus` 及 Adspower、开发者 Gmail、应用、包名、域名、Facebook、Admob、Firebase、Yandex、商店页等字段；当 `groupBy` 不包含 `projectCode` 时不返回项目元数据，CSV 导出保持原固定列格式。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，复用已有项目元数据字段。
- 回滚说明：移除项目日报查询中的项目表关联、元数据字段格式化和文档说明即可。

## 2026-06-22 修复项目报表项目元数据关联后的字段歧义

- 日期：2026-06-22
- 变更摘要：修复项目日报分组查询在关联 `project_projects` 后，`updated_at` 等聚合字段未限定主表导致 MySQL 报 `Column 'updated_at' in field list is ambiguous` 的问题；日报分组与汇总查询统一使用 `project_daily_aggregates` 前缀限定主表聚合字段。
- 影响范围：`app/Services/ProjectReportService.php`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复项目日报分组与汇总查询中未限定表名前缀的聚合字段即可。

## 2026-06-22 AID 登录自定义封禁检测规则

- 日期：2026-06-22
- 变更摘要：新增 `aid_login_ban_rules` 规则表和管理端规则接口，支持按有效截止时间、周时间段、包名和国家匹配 `loginByAid` 新注册用户，且各检测条件均可留空表示不限制；命中后复用封禁逻辑封禁用户、清理 session 并记录注册 IP。`loginByAid` 元数据新增 `package_name/packageName` 兼容，并将 `country` 归一为大写。
- 影响范围：`database/migrations/2026_06_22_160000_create_aid_login_ban_rules_table.php`、`app/Models/AidLoginBanRule.php`、`app/Services/AidLoginBanRuleService.php`、`app/Services/BlockedUserIpService.php`、`app/Services/Auth/LoginService.php`、`app/Http/Controllers/V3/Admin/UserController.php`、`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V1/Passport/AuthController.php`、`app/Http/Controllers/V3/Passport/AuthController.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_22_160000_create_aid_login_ban_rules_table.php`。
- 回滚说明：回滚新增迁移并移除 AID 自定义规则 Service、模型、请求类、admin 规则接口、`loginByAid` 规则检测调用和文档说明即可。

## 2026-06-22 AID 封禁规则支持项目代号扩展包名

- 日期：2026-06-22
- 变更摘要：AID 登录自定义封禁规则新增 `projectCodes` 项目代号数组字段；保存或更新规则时会按 `project_user_app_map.project_code` 查询启用映射，将对应 `app_id` 合并到最终 `packageNames`，用于 `loginByAid` 包名匹配封禁。
- 影响范围：`database/migrations/2026_06_22_161000_add_project_codes_to_aid_login_ban_rules_table.php`、`app/Models/AidLoginBanRule.php`、`app/Services/AidLoginBanRuleService.php`、`app/Http/Requests/Admin/AidLoginBanRuleSaveRequest.php`、`app/Http/Requests/Admin/AidLoginBanRuleUpdateRequest.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_22_161000_add_project_codes_to_aid_login_ban_rules_table.php`。
- 回滚说明：回滚新增迁移并移除规则保存/更新中的 `projectCodes` 校验、存储、返回和项目代号转包名逻辑，同时回退对应文档说明即可。

## 2026-06-22 项目代号与包名映射查询接口

- 日期：2026-06-22
- 变更摘要：新增管理端只读接口 `GET /api/v3/{secure_path}/projects/user-apps/mappings`，按 `project_user_app_map.project_code` 分组返回项目代号与包名（`app_id`）映射，供 AID 封禁规则配置页查看 `projectCodes` 会扩展出的 `packageNames`。
- 影响范围：`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V3/Admin/Project/ProjectUserAppMapController.php`、`app/Http/Requests/Admin/ProjectUserAppMapMappingRequest.php`、`app/Services/ProjectUserAppMapService.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/project_api.md`、`version.md`
- 是否需要迁移：否，复用已有 `project_user_app_map` 表。
- 回滚说明：移除新增路由、控制器方法、Request、Service 查询方法、测试和项目 API 文档说明即可。

## 2026-06-23 AID 封禁规则包名必需检测

- 日期：2026-06-23
- 变更摘要：调整 `loginByAid` 自定义封禁规则命中逻辑，规则最终 `packageNames` 为空时不再参与封禁检测；即每条规则必须配置包名，或通过 `projectCodes` 成功扩展出包名后才会继续判断时间、国家等条件。
- 影响范围：`app/Services/AidLoginBanRuleService.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：将空 `packageNames` 规则恢复为不限制包名并可继续参与检测，同时回退对应测试和文档说明即可。

## 2026-06-23 修正 AID 封禁规则可选字段数据库约束

- 日期：2026-06-23
- 变更摘要：新增兼容迁移，将已存在的 `aid_login_ban_rules.cutoff_at` 和 `weekly_windows` 字段修正为可空，解决早期迁移已执行环境中创建无截止时间规则时报 `Field 'cutoff_at' doesn't have a default value` 的问题。
- 影响范围：`database/migrations/2026_06_23_100000_make_aid_login_ban_rule_optional_fields_nullable.php`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_23_100000_make_aid_login_ban_rule_optional_fields_nullable.php`。
- 回滚说明：回滚该迁移会将空值填充为 `0` / `[]` 后恢复非空约束；应用层仍建议保持可空约束以匹配当前接口语义。

## 2026-06-23 AID 封禁规则支持时区和特定日期时间段

- 日期：2026-06-23
- 变更摘要：AID 登录自定义封禁规则新增必填 `timezone` 字段，并新增 `dateWindows` 特定日期时间段检测；`cutoffAt`、`weeklyWindows`、`dateWindows` 均按规则时区解释和判断，命中逻辑继续要求所有已配置条件同时满足。
- 影响范围：`database/migrations/2026_06_22_160000_create_aid_login_ban_rules_table.php`、`database/migrations/2026_06_23_110000_add_timezone_and_date_windows_to_aid_login_ban_rules_table.php`、`app/Models/AidLoginBanRule.php`、`app/Services/AidLoginBanRuleService.php`、`app/Http/Requests/Admin/AidLoginBanRuleSaveRequest.php`、`app/Http/Requests/Admin/AidLoginBanRuleUpdateRequest.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_23_110000_add_timezone_and_date_windows_to_aid_login_ban_rules_table.php`。
- 回滚说明：回滚新增迁移并移除规则保存/更新中的 `timezone`、`dateWindows` 校验、存储、返回和检测逻辑，同时回退对应测试与文档说明即可。

## 2026-06-23 项目聚合接口支持指定项目

- 日期：2026-06-23
- 变更摘要：`projects/aggregate` 与 `projects/aggregate-async` 新增可选 `projectId` 参数；聚合命令新增 `--project-id`，传入后仅按该项目代号过滤数据源并删除/重建该项目的 `project_daily_aggregates` 与 `project_report_hourly` 结果，避免重算单项目时影响同日期其他项目数据。
- 影响范围：`app/Http/Controllers/V3/Admin/Project/ProjectController.php`、`app/Http/Requests/Admin/ProjectAggregateRequest.php`、`app/Jobs/AggregateProjectDailyJob.php`、`app/Console/Commands/AggregateProjectDailyData.php`、`docs/api/project_api.md`、`docs/api/project_aggregates_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 `projectId` 请求校验、Controller/Job 参数透传、命令 `--project-id` 及按项目过滤/删除逻辑，并回退对应文档说明即可。

## 2026-06-23 Firebase 管理端接口默认今日

- 日期：2026-06-23
- 变更摘要：Firebase Analytics 查询接口未传 `start_time/end_time` 时默认使用今日 `00:00:00` 至 `23:59:59`；Firebase 聚合报表用户汇总与节点汇总查询未传 `dateFrom/dateTo` 时默认使用今日。
- 影响范围：`app/Http/Requests/Admin/FirebaseAnalyticsCommonQueryRequest.php`、`app/Http/Requests/Admin/FirebaseReportUserSummaryQueryRequest.php`、`app/Http/Requests/Admin/FirebaseReportNodeQueryRequest.php`、`app/Http/Controllers/V3/Admin/Firebase/FirebaseReportController.php`、`docs/api/firebase_analytics.md`、`docs/api/firebase_report_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 Firebase 查询 Request 中的默认日期填充，并将聚合报表查询默认范围恢复为昨天到今天，同时回退对应文档说明即可。

## 2026-06-23 V3 AID 登录封禁用户返回登录信息

- 日期：2026-06-23
- 变更摘要：仅调整 V3 `loginByAid`，新注册用户命中 IP 封禁或 AID 自定义封禁规则、以及已存在 AID 用户已封禁时，仍返回登录凭证并通过 `is_ban=true` 告知前端；V1/V2 `loginByAid` 与普通邮箱密码登录保持封禁错误。
- 影响范围：`app/Services/Auth/LoginService.php`、`app/Http/Controllers/V3/Passport/AuthController.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 V3 调用中的允许封禁登录参数，并恢复对应测试和文档说明即可。

## 2026-06-23 用户类型字段与普通登录返回

- 日期：2026-06-23
- 变更摘要：为 `v2_user` 新增 `user_type` 字符串字段，默认值为 `global`；普通邮箱密码登录成功返回新增 `user_type`，注册、AID 登录、邮件链接/Token 登录保持原返回结构；管理端用户更新与筛选白名单支持 `user_type`。
- 影响范围：`database/migrations/2026_06_23_120000_add_user_type_to_v2_user_table.php`、`app/Models/User.php`、`app/Http/Controllers/V1/Passport/AuthController.php`、`app/Http/Controllers/V3/Passport/AuthController.php`、`app/Http/Requests/Admin/UserUpdate.php`、`app/Http/Requests/Admin/UserFetch.php`、`app/Services/UserService.php`、`tests/Feature/UserTypeLoginTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_23_120000_add_user_type_to_v2_user_table.php`。
- 回滚说明：回滚新增迁移并移除普通登录返回中的 `user_type` 字段、管理端校验/筛选支持、测试、文档和本条版本记录即可。

## 2026-06-23 V3 客户端订阅 JSON 忽略封禁状态

- 日期：2026-06-23
- 变更摘要：调整 `GET /api/v3/client/sub/json` 可用性判断，订阅 JSON 接口不再因用户 `banned/is_ban` 状态拒绝返回；仍保留 token、`transfer_enable` 和 `expired_at` 等原有非封禁可用性校验。
- 影响范围：`app/Services/UserService.php`、`app/Http/Controllers/V3/Client/ClientController.php`、`tests/Feature/UserAuthParameterCompatibilityTest.php`、`docs/api/client_user_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：将 V3 `ClientController::subscribeJson()` 恢复为调用 `UserService::isAvailable()`，并移除新增 Service 方法、测试和文档说明即可。

## 2026-06-23 用户菜单字段与普通登录返回

- 日期：2026-06-23
- 变更摘要：为 `v2_user` 新增 `menus` JSON 字段，V1/V2/V3 普通邮箱密码登录成功返回新增 `menus` 数组，未配置时返回空数组；注册、AID 登录、邮件链接/Token 登录保持不返回该字段；管理端用户更新支持写入 `menus` 数组。
- 影响范围：`database/migrations/2026_06_23_121000_add_menus_to_v2_user_table.php`、`app/Models/User.php`、`app/Http/Controllers/V1/Passport/AuthController.php`、`app/Http/Controllers/V3/Passport/AuthController.php`、`app/Http/Requests/Admin/UserUpdate.php`、`app/Services/UserService.php`、`tests/Feature/UserTypeLoginTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_23_121000_add_menus_to_v2_user_table.php`。
- 回滚说明：回滚新增迁移并移除普通登录返回中的 `menus` 字段、管理端校验/写入支持、测试、文档和本条版本记录即可。

## 2026-06-23 管理端添加和更新用户支持类型与菜单字段

- 日期：2026-06-23
- 变更摘要：管理端用户添加 `POST /api/v3/admin/user/generate` 与更新 `POST /api/v3/admin/user/update` 支持处理 `user_type` 和 `menus`；单个添加与批量生成均会透传保存这两个字段，更新接口可修改或清空菜单数组。
- 影响范围：`app/Http/Controllers/V2/Admin/UserController.php`、`app/Http/Requests/Admin/UserGenerate.php`、`app/Http/Requests/Admin/UserUpdate.php`、`tests/Feature/UserTypeLoginTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：否，复用已新增的 `user_type` 与 `menus` 字段。
- 回滚说明：移除用户生成接口中的字段校验和透传逻辑，并回退对应测试、文档与本条版本记录即可。

## 2026-06-23 封禁 IP 支持批量删除

- 日期：2026-06-23
- 变更摘要：新增管理端接口 `POST /api/v3/{secure_path}/user/blockedIp/batchDelete`，支持按 `blocked_user_ips.id` 批量删除封禁 IP 记录；保留原单条删除接口不变，并返回删除数量、请求数量和不存在的记录 ID。
- 影响范围：`app/Http/Requests/Admin/BlockedUserIpBatchDeleteRequest.php`、`app/Services/BlockedUserIpService.php`、`app/Http/Controllers/V3/Admin/UserController.php`、`app/Http/Routes/V3/AdminRoute.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除批量删除 Request、Service 方法、Controller 方法、路由、测试和文档说明即可；原单条删除接口无需回滚。

## 2026-06-24 广告收益账号与应用远程同步

- 日期：2026-06-24
- 变更摘要：同步服务器管理新增 `POST /api/v3/admin/{securePath}/sync-servers/{server_id}/sync-account-meta` 与 `POST /api/v3/admin/{securePath}/sync-servers/{server_id}/sync-apps`，并将收益同步远程请求统一改为 `key` query 鉴权；新增远程同步 Service 统一校验服务器配置、发起 POST 请求、解析远程 `code/msg/data` 返回并在返回 URL 中脱敏密钥。
- 影响范围：`app/Services/SyncServerRemoteSyncService.php`、`app/Http/Controllers/V3/Admin/SyncServerController.php`、`app/Http/Routes/V3/AdminRoute.php`、`docs/api/sync_servers_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除新增 Service、Controller 方法、路由和文档说明，并将收益报表同步恢复为旧的远程 `Authorization` 请求头方式即可。

## 2026-06-24 项目报表当前小时限流标记

- 日期：2026-06-24
- 变更摘要：项目日报查询在 `groupBy` 包含 `projectCode` 时返回 `isLimited` 字段，按当前 Asia/Shanghai 小时 `ad_revenue_hourly` 中项目聚合 `SUM(matched_requests)/SUM(ad_requests)` 是否低于 `0.8` 判断；无当前小时数据或请求数为 0 时返回 `null`，CSV 导出不新增列。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除项目日报分组查询中的当前小时限流子查询、`isLimited` 格式化输出和对应文档说明即可。

## 2026-06-24 项目报表限流标记改为上一完整小时

- 日期：2026-06-24
- 变更摘要：将项目日报 `isLimited` 字段的判断时间桶从当前 Asia/Shanghai 小时调整为上一完整小时，仍按 `ad_revenue_hourly` 项目聚合 `SUM(matched_requests)/SUM(ad_requests) < 0.8` 判断。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：将限流子查询的 `report_hour` 条件恢复为当前小时，并回退对应文档说明即可。

## 2026-06-24 项目报表限流标记改用项目广告账号映射

- 日期：2026-06-24
- 变更摘要：修正项目日报 `isLimited` 字段的项目归属口径，不再依赖 `ad_revenue_hourly.project_id`，改为通过 `project_ad_platform_accounts.ad_platform_account_id = ad_revenue_hourly.account_id` 映射到 `project_code` 后聚合判断上一完整小时匹配率。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：将限流子查询恢复为按 `ad_revenue_hourly.project_id` 关联项目，并回退对应文档说明即可。

## 2026-06-24 项目报表限流标记移除平台类型限制

- 日期：2026-06-24
- 变更摘要：项目日报 `isLimited` 字段的上一完整小时限流子查询移除 `papa.platform_code = admob`、`arh.source_platform = admob` 和 `arh.report_type = network` 限制，仅按启用的项目广告账号映射、账号 ID 与上一完整小时聚合判断。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：在限流子查询中恢复上述平台和报表类型条件，并回退对应文档说明即可。

## 2026-06-24 项目报表限流标记增加零请求新增用户判断

- 日期：2026-06-24
- 变更摘要：项目日报 `isLimited` 字段新增判断：上一完整小时项目聚合 `SUM(ad_requests)=0` 且当前报表行聚合 `newUsers > 0` 时返回 `true`；`ad_requests=0` 且无新增用户时仍返回 `null`。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 `ad_requests=0 && newUsers>0` 的优先判断，并恢复文档说明即可。

## 2026-06-24 项目报表限流聚合增加缓存

- 日期：2026-06-24
- 变更摘要：项目日报 `isLimited` 字段的上一完整小时广告请求聚合结果改为服务层缓存，缓存键按小时桶区分，TTL 为 1 分钟；分页报表行再结合各自聚合 `newUsers` 计算最终限流状态。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 `isLimited` 聚合结果缓存，恢复每次查询实时读取上一完整小时广告请求聚合数据即可。

## 2026-06-25 邀请统计返回被邀请用户列表

- 日期：2026-06-25
- 变更摘要：`GET /api/v3/user/invite/summary` 在保留 `invitedUsers` 总人数的基础上新增 `users` 列表，返回被邀请用户 ID、用户标识和使用邀请码时间；注册和补填邀请码时记录 `register_metadata.invite_code_used_at`，历史数据回退使用用户注册时间。
- 影响范围：`app/Services/InviteService.php`、`app/Services/Auth/RegisterService.php`、`tests/Feature/UserAuthParameterCompatibilityTest.php`、`docs/api/user_api.md`、`docs/api/client_user_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 `summary` 中 `users` 返回、邀请码使用时间 metadata 写入逻辑、对应测试和文档说明即可。

## 2026-06-25 项目管理批量更新投放状态

- 日期：2026-06-25
- 变更摘要：项目管理新增 `POST /api/v3/admin/{securePath}/projects/batch-update-ad-status` 接口，支持按项目 ID 数组批量更新或清空 `project_projects.ad_status`，返回请求数量、实际更新数量和不存在的项目 ID。
- 影响范围：`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V3/Admin/Project/ProjectController.php`、`app/Http/Requests/Admin/ProjectBatchUpdateAdStatusRequest.php`、`app/Services/ProjectService.php`、`docs/api/project_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除新增 Request、Controller 方法、Service 批量更新方法、路由和文档说明即可。

## 2026-06-25 项目应用平台字段与报表筛选

- 日期：2026-06-25
- 变更摘要：`project_projects` 新增 `app_platform` 应用平台字段；项目管理接口支持 `appPlatform` 读写和列表筛选；项目日报查询、项目日报 CSV 导出与项目小时报表支持通过 `filters.appPlatforms` 按应用平台过滤，且当项目日报返回行包含唯一 `projectCode` 时返回 `appPlatform` 元数据。
- 影响范围：`database/migrations/2026_06_25_120000_add_app_platform_to_project_projects_table.php`、`app/Services/ProjectService.php`、`app/Services/ProjectReportService.php`、`app/Http/Resources/ProjectResource.php`、`app/Http/Requests/Admin/ProjectFetchRequest.php`、`app/Http/Requests/Admin/ProjectSaveRequest.php`、`app/Http/Requests/Admin/ProjectUpdateRequest.php`、`app/Http/Requests/Admin/ProjectAggregateDailyQueryRequest.php`、`app/Http/Requests/Admin/ProjectAggregateDailyExportRequest.php`、`app/Http/Requests/Admin/ProjectReportHourlyQueryRequest.php`、`docs/api/project_api.md`、`docs/api/project_report_query_api.md`、`docs/api/project_report_hourly_api.md`、`docs/api/application_route_api.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_06_25_120000_add_app_platform_to_project_projects_table.php`。
- 回滚说明：回滚新增迁移并移除 `appPlatform` 读写、返回、筛选校验和报表过滤逻辑，同时回退对应文档说明即可。

## 2026-06-25 项目管理批量更新应用平台

- 日期：2026-06-25
- 变更摘要：项目管理新增 `POST /api/v3/admin/{securePath}/projects/batch-update-app-platform` 接口，支持按项目 ID 数组批量更新或清空 `project_projects.app_platform`，返回请求数量、实际更新数量和不存在的项目 ID。
- 影响范围：`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V3/Admin/Project/ProjectController.php`、`app/Http/Requests/Admin/ProjectBatchUpdateAppPlatformRequest.php`、`app/Services/ProjectService.php`、`docs/api/project_api.md`、`version.md`
- 是否需要迁移：否，依赖 `2026_06_25_120000_add_app_platform_to_project_projects_table.php` 已新增的 `app_platform` 字段。
- 回滚说明：移除新增 Request、Controller 方法、Service 批量更新方法、路由和文档说明即可。
