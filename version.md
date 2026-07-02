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

## 2026-06-25 当前收益聚合表远程回填同步

- 日期：2026-06-25
- 变更摘要：同步服务器管理新增 `POST /api/v3/admin/{securePath}/sync-servers/{server_id}/sync-revenue-now-backfill`，触发远程节点 `POST /api/sync/revenue-now/backfill?key=...`，复用远程同步 Service 的 key 鉴权、远程 `code/msg/data` 解析和返回 URL 脱敏处理。
- 影响范围：`app/Services/SyncServerRemoteSyncService.php`、`app/Http/Controllers/V3/Admin/SyncServerController.php`、`app/Http/Routes/V3/AdminRoute.php`、`docs/api/sync_servers_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除新增 endpoint 常量、Controller 方法、路由和文档说明即可；其他同步接口不受影响。

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

## 2026-06-25 项目报表限流零请求判断修正

- 日期：2026-06-25
- 变更摘要：项目日报 `isLimited` 字段在上一完整小时广告请求为 0 时，新增通过 `project_report_hourly.install_users` 判断上一小时新增，并通过上一完整小时所属日期、同项目代号在 `project_daily_aggregates` 中聚合的 `SUM(ad_requests)` 判断当日请求；当上一小时请求为 0、上一小时新增大于 0 且当日请求大于 0 时返回 `true`，否则返回 `null`；匹配率阈值保持 `0.7`，缓存键升级为 `project_report:is_limited_metrics:v3:{hour}`。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除上一小时新增与当日请求参与限流判断的逻辑，并将缓存键恢复为旧前缀即可。

## 2026-06-25 当前收益与日报收益差值查询接口

- 日期：2026-06-25
- 变更摘要：管理端广告收益模块新增 `POST /api/v3/admin/{securePath}/ad-revenue/now-diff` 接口，以 `ad_revenue_daily_now` 为主表，对齐 `ad_revenue_daily` 返回当前收益、日报收益和 `estimatedEarningsDiff`；支持按账号、日期、APP、设备平台、来源平台、报表类型和项目代号筛选，项目代号按 `project_ad_platform_accounts` 的 APP 级优先、账号级兜底口径映射。
- 影响范围：`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V3/Admin/AdRevenuePlatform/AdRevenueController.php`、`app/Http/Requests/Admin/AdRevenueNowDiffRequest.php`、`docs/api/ad_revenue_api.md`、`version.md`
- 是否需要迁移：否，依赖已存在的 `ad_revenue_daily_now`、`ad_revenue_daily` 和 `project_ad_platform_accounts` 表结构。
- 回滚说明：移除新增路由、Controller 方法、Request 类和接口文档说明即可。

## 2026-06-25 广告收益管理查询逻辑迁移到 Service

- 日期：2026-06-25
- 变更摘要：将广告收益管理 Controller 中的明细、聚合、趋势、汇总、APP 列表、Top 排行和当前收益差值查询实现迁移到 `AdRevenueService`，Controller 仅负责接收 Form Request、调用 Service 和返回统一响应。
- 影响范围：`app/Http/Controllers/V3/Admin/AdRevenuePlatform/AdRevenueController.php`、`app/Services/AdRevenueService.php`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：将查询实现从 `AdRevenueService` 移回 Controller，并移除新增 Service 即可。

## 2026-06-25 当前收益表查询接口

- 日期：2026-06-25
- 变更摘要：管理端广告收益模块新增 `POST /api/v3/admin/{securePath}/ad-revenue/now` 接口，仅查询 `ad_revenue_daily_now` 当前收益表并返回当前收益、项目代号映射和更新时间，不访问 `ad_revenue_daily`，也不计算日报收益或收益差值。
- 影响范围：`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V3/Admin/AdRevenuePlatform/AdRevenueController.php`、`app/Http/Requests/Admin/AdRevenueNowRequest.php`、`app/Services/AdRevenueService.php`、`docs/api/ad_revenue_api.md`、`version.md`
- 是否需要迁移：否，依赖已存在的 `ad_revenue_daily_now` 和 `project_ad_platform_accounts` 表结构。
- 回滚说明：移除新增 Request、Controller 方法、Service 查询方法、路由和文档说明即可。

## 2026-06-25 项目日报返回当前收益与收益差值

- 日期：2026-06-25
- 变更摘要：项目日报查询返回行在包含唯一 `projectCode` 时新增 `adRevenueNow` 与 `adRevenueDiff` 字段；当前收益复用 `AdRevenueService::now()` 的 `ad_revenue_daily_now` 与项目映射口径，按 `reportDate + projectCode` 或请求日期范围内的 `projectCode` 聚合，收益差值按 `adRevenueNow - adRevenue` 计算。
- 影响范围：`app/Services/AdRevenueService.php`、`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除项目日报行的当前收益附加逻辑、`AdRevenueService` 聚合复用方法和对应文档说明即可。

## 2026-06-25 项目报表移除第三方元数据返回字段

- 日期：2026-06-25
- 变更摘要：项目日报在返回项目元数据时，不再输出 `facebook*`、`admob*`、`yandex*` 开头字段，以及 `privacyPolicyUrl`、`termsUrl`、`storePageUrl`、`firebaseConfigNote`；项目管理接口和项目表字段保持不变。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：将上述字段重新加入项目报表元数据映射，并恢复项目报表文档说明即可。

## 2026-06-26 停止项目小时报同步并分批写入日报聚合

- 日期：2026-06-26
- 变更摘要：`project:aggregate-daily` 停止同步生成或刷新 `project_report_hourly`，仅继续聚合 `project_daily_aggregates`；日报聚合 upsert 改为分批写入，避免数据量较大时触发 MySQL prepared statement 占位符数量上限。
- 影响范围：`app/Console/Commands/AggregateProjectDailyData.php`、`docs/api/project_report_hourly_api.md`、`docs/api/project_aggregates_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复 `aggregateHourlyReportOneDate()` 调用和小时表写入逻辑，并将日报 upsert 改回单次写入即可。

## 2026-06-26 用户上报失败记录分批写入

- 日期：2026-06-26
- 变更摘要：`user_report:aggregate` 写入 `v3_user_report_node_fail` 时改为每 500 条分批 `insert`，避免失败记录较多时触发 MySQL prepared statement 占位符数量上限。
- 影响范围：`app/Console/Commands/AggregateUserReport.php`、`version.md`
- 是否需要迁移：否，无数据库结构变更，不改变对外接口。
- 回滚说明：将失败记录写入恢复为单次 `insert($insertRows)` 即可。

## 2026-06-26 聚合同步写库批量化

- 日期：2026-06-26
- 变更摘要：`report_hourly:aggregate`、`perf:aggregate` 中具备稳定唯一键的聚合表写入改为每 500 条分批 `upsert`；投放消耗日报同步改为分批 `upsert`；`StatUserJob` 在 MySQL/MariaDB 路径下按 500 条批量写入用户流量统计，降低逐行写入开销并避免单条 SQL 过大风险。
- 影响范围：`app/Console/Commands/AggregateReportHourly.php`、`app/Console/Commands/AggregatePerformanceReports.php`、`app/Services/AdSpendSyncService.php`、`app/Jobs/StatUserJob.php`、`version.md`
- 是否需要迁移：否，无数据库结构变更，不改变对外接口。
- 回滚说明：将上述写入逻辑恢复为原逐行 `updateOrInsert` / `updateOrCreate` / 单行 `upsert` 即可。

## 2026-06-26 项目日报 Summary 当前收益字段

- 日期：2026-06-26
- 变更摘要：项目日报查询的 `summary` 新增 `adRevenueNow` 与 `adRevenueDiff`，按当前筛选条件下全量项目和日期范围汇总当前收益，不受分页影响；收益差值按 `adRevenueNow - summary.adRevenue` 计算。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更，不改变请求参数。
- 回滚说明：移除 `summary` 当前收益计算与返回字段，并删除对应文档说明即可。

## 2026-06-26 项目报表查询 1 分钟缓存

- 日期：2026-06-26
- 变更摘要：项目日报和项目小时报表 JSON 查询结果增加 60 秒缓存，缓存 key 覆盖日期、小时、分组、筛选、分页和排序参数；CSV 导出保持实时流式查询，不使用查询缓存。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`docs/api/project_report_hourly_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更，不改变对外接口。
- 回滚说明：移除 `ProjectReportService` 查询缓存包装和缓存 key 归一化方法，并删除对应文档说明即可。

## 2026-06-26 项目管理 department 创建更新修复

- 日期：2026-06-26
- 变更摘要：补齐 `project_projects.department` 字段迁移，并修复项目创建、编辑接口对 `department` 参数的校验和保存逻辑；编辑时传 `null` 可清空所属部门。
- 影响范围：`database/migrations/2026_06_26_120000_add_department_to_project_projects_table.php`、`app/Http/Requests/Admin/ProjectSaveRequest.php`、`app/Http/Requests/Admin/ProjectUpdateRequest.php`、`app/Services/ProjectService.php`、`version.md`
- 是否需要迁移：是，需要执行新增 migration；回滚会删除 `project_projects.department` 字段。
- 回滚说明：回滚该 migration，并移除 Request 与 Service 中的 `department` 处理逻辑即可。

## 2026-06-26 项目部门批量更新与列表接口

- 日期：2026-06-26
- 变更摘要：项目管理新增 `POST /projects/batch-update-department` 批量更新部门接口，以及 `GET /projects/departments` 部门列表接口；部门列表直接从 `project_projects.department` 现有非空数据去重返回，不新增部门表。
- 影响范围：`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V3/Admin/Project/ProjectController.php`、`app/Http/Requests/Admin/ProjectBatchUpdateDepartmentRequest.php`、`app/Services/ProjectService.php`、`docs/api/project_api.md`、`version.md`
- 是否需要迁移：依赖本次已新增的 `project_projects.department` 字段迁移；接口本身不新增额外表结构。
- 回滚说明：移除新增路由、Controller 方法、Request 类、Service 方法和对应文档说明即可。

## 2026-06-26 项目批量保存接口

- 日期：2026-06-26
- 变更摘要：项目管理新增 `POST /projects/batch-save` 批量保存接口，按 `projectCode` 判断创建或更新项目主表；更新已有项目时只更新显式传入字段，未传字段保持原值；接口接收任意数量并在 Service 内每 100 条分批处理，不处理项目关联内容。
- 影响范围：`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V3/Admin/Project/ProjectController.php`、`app/Http/Requests/Admin/ProjectBatchSaveRequest.php`、`app/Services/ProjectService.php`、`docs/api/project_api.md`、`version.md`
- 是否需要迁移：否，无新增数据库结构；依赖 `project_projects` 现有字段。
- 回滚说明：移除新增路由、Controller 方法、Request 类、Service 批量保存方法和对应文档说明即可。

## 2026-06-26 工单多开与个人邮箱字段

- 日期：2026-06-26
- 变更摘要：用户创建工单时不再限制同一用户只能存在一个未关闭工单；创建工单新增可选 `personal_email` 个人邮箱字段并在用户端工单查询响应中返回。
- 影响范围：`database/migrations/2026_06_26_130000_add_personal_email_to_v2_ticket_table.php`、`app/Http/Requests/User/TicketSave.php`、`app/Services/TicketService.php`、`app/Http/Controllers/V1/User/TicketController.php`、`app/Http/Controllers/V3/User/TicketController.php`、`app/Http/Resources/TicketResource.php`、`docs/api/ticket_api.md`、`tests/Feature/TicketCreateTest.php`、`version.md`
- 是否需要迁移：是，需要执行新增 migration；回滚会删除 `v2_ticket.personal_email` 字段。
- 回滚说明：回滚该 migration，并恢复 `TicketService::createTicket()` 的未关闭工单限制，移除请求校验、传参、Resource 返回字段、测试和文档说明即可。

## 2026-06-29 投放消耗同步改为 10 分钟并优化大数据处理

- 日期：2026-06-29
- 变更摘要：`ad-spend:sync --lookback-days=2` 调度从每小时第 5 分钟改为每 10 分钟执行；同步命令按账号分批遍历，单账号同步按投放平台分页流式处理并继续分批 upsert，降低大量日报数据下的内存峰值和单条 SQL 体积。
- 影响范围：`app/Console/Kernel.php`、`app/Console/Commands/SyncAdSpendReports.php`、`app/Services/AdSpendPlatformService.php`、`app/Services/AdSpendSyncService.php`、`tests/Feature/AdSpendSyncServiceTest.php`、`docs/api/ad_spend_sync_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更；仅调整调度频率和同步处理流程。
- 回滚说明：将调度恢复为 `hourlyAt(5)->withoutOverlapping(55)`，命令恢复为一次性加载账号集合，投放平台日报同步恢复为一次性拉取全部记录后处理即可。

## 2026-06-29 Firebase 探测结果明细接口

- 日期：2026-06-29
- 变更摘要：新增管理端 `GET /api/v3/{secure_path}/firebase-analytics/vpn-probe/results` 接口，支持分页查看 `firebase_event_vpn_probe_result` 探测结果明细，并可按事件、探测批次、节点、成功状态和错误码筛选。
- 影响范围：`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V3/Admin/Firebase/FirebaseAnalyticsVpnProbeController.php`、`app/Http/Requests/Admin/FirebaseAnalyticsProbeResultsRequest.php`、`app/Services/FirebaseAnalyticsService.php`、`tests/Feature/FirebaseAnalyticsProbeResultsTest.php`、`docs/api/firebase_analytics.md`、`version.md`
- 是否需要迁移：否，复用现有 Firebase 事件与探测结果表。
- 回滚说明：移除新增路由、Controller 方法、Request 类、Service 查询方法、测试和文档说明即可。

## 2026-06-29 Firebase Analytics 查询优化

- 日期：2026-06-29
- 变更摘要：优化 Firebase Analytics 统计与排行接口，修复计算字段直接排序可能导致的 SQL 风险；地区质量、节点排行和协议错误码改为批量聚合查询；VPN 质量趋势和 App 打开趋势复用 60 秒缓存并批量计算 P95；补充高频过滤、排行和明细查询索引。
- 影响范围：`app/Services/FirebaseAnalyticsService.php`、`database/migrations/2026_06_29_140000_add_firebase_analytics_query_indexes.php`、`tests/Feature/FirebaseAnalyticsProbeResultsTest.php`、`docs/api/firebase_analytics.md`、`version.md`
- 是否需要迁移：是，需要执行新增索引 migration；回滚会删除本次新增的 Firebase 查询优化索引，不删除业务数据。
- 回滚说明：回滚新增 migration，并恢复 Firebase Analytics Service 中排序映射、批量 P95/Top 错误码聚合、趋势缓存和文档说明即可。

## 2026-06-29 Firebase 探测节点统计接口

- 日期：2026-06-29
- 变更摘要：新增管理端 `GET /api/v3/{secure_path}/firebase-analytics/vpn-probe/node-stats` 接口，按节点、区域和协议分页返回探测次数、成功数、失败数、成功率、平均/P95 延迟、主要错误码和最近上报时间。
- 影响范围：`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V3/Admin/Firebase/FirebaseAnalyticsVpnProbeController.php`、`app/Http/Requests/Admin/FirebaseAnalyticsProbeNodeStatsRequest.php`、`app/Services/FirebaseAnalyticsService.php`、`tests/Feature/FirebaseAnalyticsProbeResultsTest.php`、`docs/api/firebase_analytics.md`、`version.md`
- 是否需要迁移：否，复用现有 Firebase 事件与探测结果表；已有查询优化索引可辅助该接口。
- 回滚说明：移除新增路由、Controller 方法、Request 类、Service 统计方法、测试和文档说明即可。

## 2026-06-29 Firebase 节点状态与连接明细接口

- 日期：2026-06-29
- 变更摘要：新增管理端 `GET /api/v3/{secure_path}/firebase-analytics/nodes/status`、`/nodes/connection-summary`、`/nodes/connection-error-distribution`、`/nodes/connection-results` 接口，支持查看探测与连接合并后的节点状态、节点连接摘要、连接错误分布和连接明细。
- 影响范围：`app/Http/Routes/V3/AdminRoute.php`、`app/Http/Controllers/V3/Admin/Firebase/FirebaseAnalyticsNodeController.php`、`app/Http/Requests/Admin/FirebaseAnalyticsNodesStatusRequest.php`、`app/Http/Requests/Admin/FirebaseAnalyticsNodeConnectionSummaryRequest.php`、`app/Http/Requests/Admin/FirebaseAnalyticsNodeConnectionErrorDistributionRequest.php`、`app/Http/Requests/Admin/FirebaseAnalyticsNodeConnectionResultsRequest.php`、`app/Services/FirebaseAnalyticsService.php`、`tests/Feature/FirebaseAnalyticsProbeResultsTest.php`、`docs/api/firebase_analytics.md`、`version.md`
- 是否需要迁移：否，复用现有 `firebase_event_vpn_session`、`firebase_event_vpn_probe_result`、`firebase_event_common` 表；已有查询优化索引可辅助节点查询。
- 回滚说明：移除新增路由、Controller 方法、Request 类、Service 查询与统计方法、测试和文档说明即可。

## 2026-06-29 项目日报限流 hourly_status 字段

- 日期：2026-06-29
- 变更摘要：项目日报在返回 `isLimited` 的项目维度行中新增 `hourly_status` 字段；当上一小时广告请求数为 0 时，使用位运算 `|` 组合状态：`1` 表示小时广告请求为 0、`2` 表示小时用户新增为 0、`4` 表示日新增为 0，正常为 `0`；限流指标缓存键升级到 `project_report:is_limited_metrics:v4:{hour}`，项目报表查询缓存键升级到 `project_report:{scope}_query:v2:{hash}`。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 `hourly_status` 计算与返回字段，并将限流缓存键恢复为上一版本即可。

## 2026-06-29 项目小时报表重建与同步接口

- 日期：2026-06-29
- 变更摘要：重建 `project_report_hourly` 表结构，使其与 `project_daily_aggregates` 字段保持一致并额外增加 `hour` 字段；新增 `project:aggregate-hourly` 命令，从 `v3_user_report_count`、`traffic_platform_usage_hourly`、`ad_revenue_hourly` 聚合小时数据，投放小时数据暂置 0/null；新增管理端 `POST /projects/aggregate-hourly` 手动同步接口，并恢复每 5 分钟调度刷新当前小时和上一小时。
- 影响范围：`database/migrations/2026_06_29_160000_rebuild_project_report_hourly_table.php`、`app/Console/Commands/AggregateProjectHourlyData.php`、`app/Console/Kernel.php`、`app/Http/Controllers/V3/Admin/Project/ProjectController.php`、`app/Http/Requests/Admin/ProjectAggregateHourlyRequest.php`、`app/Http/Requests/Admin/ProjectReportHourlyQueryRequest.php`、`app/Http/Routes/V3/AdminRoute.php`、`app/Services/ProjectReportService.php`、`docs/api/project_report_hourly_api.md`、`docs/api/project_api.md`、`version.md`
- 是否需要迁移：是，需要执行新增 migration；该迁移会删除并重建 `project_report_hourly`，旧小时表数据会被清空。
- 回滚说明：回滚该 migration 会恢复旧 `date/install_users/ros` 结构；同时需移除新命令、调度、手动同步接口和小时查询字段映射调整。

## 2026-06-29 日报命令小时逻辑兼容新小时表

- 日期：2026-06-29
- 变更摘要：`project:aggregate-daily` 中遗留的 `aggregateHourlyReportOneDate()` 明确改为 no-op，避免误调用旧的按日报分摊小时逻辑；遗留删除方法的日期列名同步为新小时表 `report_date`，防止后续误触发旧 `date` 字段错误。
- 影响范围：`app/Console/Commands/AggregateProjectDailyData.php`、`docs/api/project_aggregates_api.md`、`version.md`
- 是否需要迁移：否；依赖本次 `project_report_hourly` 重建迁移。
- 回滚说明：如需恢复日报命令写小时表，需要重新实现为新字段口径，不建议回滚到旧 `date/install_users/ros` 逻辑。

## 2026-06-29 项目日报限流兼容新小时表字段

- 日期：2026-06-29
- 变更摘要：项目日报 `isLimited/hourly_status` 辅助查询从 `project_report_hourly.date` 切换为新字段 `report_date`，并将限流指标缓存键升级到 `project_report:is_limited_metrics:v5:{hour}`，避免新旧小时表结构切换后读取旧缓存或旧字段报错。
- 影响范围：`app/Services/ProjectReportService.php`、`version.md`
- 是否需要迁移：否；依赖本次 `project_report_hourly` 重建迁移。
- 回滚说明：如回滚到旧小时表结构，需要同步恢复限流查询列名和缓存键。

## 2026-06-30 项目小时表调度与保留周期调整

- 日期：2026-06-30
- 变更摘要：`project:aggregate-hourly` 调度从每 5 分钟调整为每小时第 5 分钟执行；新增 `project:prune-hourly` 清理命令，`project_report_hourly` 仅保留最近 30 天数据，并在每天 `00:30` 分批清理 30 天前数据。
- 影响范围：`app/Console/Kernel.php`、`app/Console/Commands/PruneProjectHourlyReport.php`、`docs/api/project_report_hourly_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 `project:prune-hourly` 调度和命令，并将 `project:aggregate-hourly` 调度恢复为每 5 分钟即可。

## 2026-06-30 项目小时报表返回限流标记

- 日期：2026-06-30
- 变更摘要：项目小时报表 JSON 返回行新增 `isLimited` 字段，直接根据当前行 `adMatchRate < 70` 判断是否限流；`adMatchRate` 为空时返回 `null`，并升级项目报表查询缓存键版本避免旧缓存短时间缺少新字段。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_hourly_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除小时行 `isLimited` 格式化逻辑并恢复缓存键版本即可。

## 2026-06-30 投放小时数据同步与项目小时聚合接入

- 日期：2026-06-30
- 变更摘要：新增 `ad_spend_report_hourly` 小时投放表，新增投放平台小时报表拉取、手动同步接口 `POST /ad-spend-platform/sync-hourly`、定时命令 `ad-spend:sync-hourly` 和 30 天清理命令 `ad-spend:prune-hourly`；项目小时聚合 `project:aggregate-hourly` 接入小时投放成本并重算 CPI、CPC、CPM、利润和 ROI。
- 影响范围：`database/migrations/2026_06_30_120000_create_ad_spend_report_hourly_table.php`、`app/Models/AdSpendHourlyReport.php`、`app/Services/AdSpendPlatformService.php`、`app/Services/AdSpendSyncService.php`、`app/Console/Commands/SyncAdSpendHourlyReports.php`、`app/Console/Commands/PruneAdSpendHourlyReport.php`、`app/Console/Commands/AggregateProjectHourlyData.php`、`app/Console/Kernel.php`、`app/Http/Controllers/V3/Admin/AdSpendPlatform/AdSpendPlatformController.php`、`app/Http/Requests/Admin/AdSpendPlatformHourlySyncRequest.php`、`app/Http/Routes/V3/AdminRoute.php`、`docs/api/ad_spend_sync_api.md`、`docs/api/project_report_hourly_api.md`、`version.md`
- 是否需要迁移：是，需要执行新增 migration 创建 `ad_spend_report_hourly`。
- 回滚说明：回滚新增 migration，并移除小时同步命令、清理命令、手动同步接口和项目小时聚合中的投放小时表读取逻辑。

## 2026-06-30 投放小时同步分页完整性补强

- 日期：2026-06-30
- 变更摘要：小时投放同步新增远程分页完整性校验，读取 `total/current/size` 判断是否拉全；提前空页、页码异常或超出页数上限时任务失败并记录错误。未匹配项目代号的数据继续不落未匹配明细表，仅累计 `unmatched_records`。
- 影响范围：`app/Services/AdSpendPlatformService.php`、`app/Services/AdSpendSyncService.php`、`docs/api/ad_spend_sync_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复小时分页按空页结束的旧逻辑即可。

## 2026-06-30 投放日报未匹配统计策略调整

- 日期：2026-06-30
- 变更摘要：投放日报同步匹配不到项目代号的数据不再累计 `unmatched_records`，也不写入未匹配明细表，直接跳过；小时同步保持仅累计 `unmatched_records` 的策略。
- 影响范围：`app/Services/AdSpendSyncService.php`、`docs/api/ad_spend_sync_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：恢复日报同步中 `$row === null` 时累加 `$unmatchedRecords` 的逻辑即可。

## 2026-06-30 项目报表 Top3 收益国家返回

- 日期：2026-06-30
- 变更摘要：项目日报和项目小时报表 JSON 查询返回行新增 `topRevenueCountries` 字段，按当前行可确定的日期、小时、项目、国家维度及请求筛选条件批量聚合收益 Top3 国家和占比；查询缓存 key 升级到 `v6`，避免旧缓存缺少新字段。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`docs/api/project_report_hourly_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 `topRevenueCountries` 批量聚合与格式化逻辑，并将项目报表查询缓存 key 恢复到上一版本即可。
## 2026-06-30 项目小时报表 Summary 返回

- 日期：2026-06-30
- 变更摘要：项目小时报表 JSON 查询新增 `summary` 返回，按当前小时查询筛选条件全量汇总，不受分页影响；汇总口径对齐小时列表聚合字段，并将项目报表查询缓存 key 升级到 `v7`。
- 影响范围：`app/Services/ProjectReportService.php`、`docs/api/project_report_hourly_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除小时查询返回中的 `summary` 和 `buildHourlySummary()`，并将项目报表查询缓存 key 恢复到上一版本即可。

## 2026-06-30 项目昨日流量飞书日报定时任务

- 日期：2026-06-30
- 变更摘要：新增 `project:send-yesterday-traffic-report` 命令，按 active 项目汇总昨日 `project_daily_aggregates.traffic_usage_mb` 并以 GB 展示，通过现有 `SendWebhookJob` 发送到飞书机器人 webhook；新增飞书日报配置项并在 Kernel 中配置每日 `09:30` 调度。
- 影响范围：`app/Console/Commands/SendProjectYesterdayTrafficReport.php`、`app/Console/Kernel.php`、`config/services.php`、`.env.example`、`docs/command_help.md`、`tests/Feature/ProjectYesterdayTrafficReportCommandTest.php`、`version.md`
- 是否需要迁移：否，无数据库结构变更；部署时需要在运行环境 `.env` 配置 `FEISHU_PROJECT_TRAFFIC_REPORT_WEBHOOK_URL`。
- 回滚说明：移除新增命令、Kernel 调度、飞书配置项、命令文档和测试即可；无需 migration 回滚。

## 2026-06-30 项目昨日流量飞书日报分组展示

- 日期：2026-06-30
- 变更摘要：调整 `project:send-yesterday-traffic-report` 飞书消息明细，按 `project_projects.department` 分组展示，并在项目行增加 `owner_name` 负责人值；部门和负责人仅展示值，不额外增加描述标签。
- 影响范围：`app/Console/Commands/SendProjectYesterdayTrafficReport.php`、`docs/command_help.md`、`tests/Feature/ProjectYesterdayTrafficReportCommandTest.php`、`version.md`
- 是否需要迁移：否，复用既有 `department` 与 `owner_name` 字段。
- 回滚说明：恢复日报明细为未分组项目列表，并移除负责人值展示即可；无需 migration 回滚。

## 2026-07-01 项目昨日流量飞书日报过滤未绑定流量账户项目

- 日期：2026-07-01
- 变更摘要：调整 `project:send-yesterday-traffic-report` 项目范围，先查询 active 项目日报数据，再单独查询 `project_traffic_platform_accounts` 中启用的代理流量账户绑定关系，并在 PHP 层过滤掉未绑定代理流量账户的项目；避免在 SQL 中联表过滤绑定关系。
- 影响范围：`app/Console/Commands/SendProjectYesterdayTrafficReport.php`、`docs/command_help.md`、`tests/Feature/ProjectYesterdayTrafficReportCommandTest.php`、`version.md`
- 是否需要迁移：否，复用既有 `project_traffic_platform_accounts` 绑定关系表。
- 回滚说明：移除命令中的绑定关系查询和 PHP 层过滤逻辑，并恢复文档与测试断言即可；无需 migration 回滚。

## 2026-07-01 项目小时聚合默认刷新当天当前范围

- 日期：2026-07-01
- 变更摘要：`project:aggregate-hourly` 在不传日期和小时参数的默认调度路径下，从刷新“当前小时 + 上一小时”调整为刷新当天 `0` 点到当前小时，覆盖投放小时数据后续回刷造成的当日历史小时变化；同时在删除 `project_report_hourly` 前先确认存在可重建数据，降低源数据临时为空时误清空聚合数据的风险。
- 影响范围：`app/Console/Commands/AggregateProjectHourlyData.php`、`docs/api/project_report_hourly_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：将默认小时桶恢复为当前小时和上一小时，并恢复原文档说明即可。

## 2026-07-02 AID 已有用户 channel_type 异步补更新

- 日期：2026-07-02
- 变更摘要：新增 `aid_channel_type_update_queues` 队列表；`loginByAid` 对已存在 AID 用户仅在当前 `register_metadata.channel_type` 转大写为 `UNKNOWN` 且本次传入的新 `channel_type` 非 `UNKNOWN` 时入队，后台命令 `aid-channel-type:flush` 每 5 分钟批量只更新 `register_metadata.channel_type` 字段，不覆盖其他 metadata。
- 影响范围：`database/migrations/2026_07_02_120000_create_aid_channel_type_update_queues_table.php`、`app/Models/AidChannelTypeUpdateQueue.php`、`app/Services/AidChannelTypeUpdateQueueService.php`、`app/Services/Auth/LoginService.php`、`app/Console/Commands/FlushAidChannelTypeUpdates.php`、`app/Console/Kernel.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`docs/command_help.md`、`version.md`
- 是否需要迁移：是，需执行新增迁移 `2026_07_02_120000_create_aid_channel_type_update_queues_table.php`。
- 回滚说明：回滚新增迁移并移除队列模型、Service、命令、Kernel 调度、`loginByAid` 入队调用、测试和文档说明即可；如需恢复旧行为，可将已存在 AID 用户登录时的完整 `register_metadata` 直接合并写回逻辑恢复。

## 2026-07-02 项目报表排除筛选

- 日期：2026-07-02
- 变更摘要：项目日报查询、项目日报 CSV 导出和项目小时报表查询新增 `filters.exclude.projectCodes`、`filters.exclude.countries` 排除筛选；正向筛选与排除筛选同时存在时按先包含再排除处理；日报投放子查询、Top3 收益国家和小时 summary 同步使用该口径，并将项目报表查询缓存 key 升级到 `v8`。
- 影响范围：`app/Http/Requests/Admin/ProjectAggregateDailyQueryRequest.php`、`app/Http/Requests/Admin/ProjectAggregateDailyExportRequest.php`、`app/Http/Requests/Admin/ProjectReportHourlyQueryRequest.php`、`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`docs/api/project_report_hourly_api.md`、`docs/api/application_route_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 Request 中 `filters.exclude.*` 校验、Service 排除过滤逻辑和文档说明，并将项目报表查询缓存 key 恢复到上一版本即可。
## 2026-07-02 邀请码安全解封与封禁 IP 类型
- 日期：2026-07-02
- 变更摘要：`blocked_user_ips` 新增 `type` 字段，支持 `normal` / `dangerous` 两类封禁 IP；V3 管理端批量封禁可指定封禁 IP 类型，封禁 IP 查询可按类型筛选并返回类型；用户使用邀请码绑定邀请人后，如果当前用户处于封禁状态且邀请人与当前用户注册 IP 都未命中 `dangerous` 类型封禁列表，则自动解除当前用户封禁并同步节点。
- 影响范围：`database/migrations/2026_07_02_130000_add_type_to_blocked_user_ips_table.php`、`app/Models/BlockedUserIp.php`、`app/Services/BlockedUserIpService.php`、`app/Services/InviteService.php`、`app/Http/Requests/Admin/UserBatchBanRequest.php`、`app/Http/Requests/Admin/BlockedUserIpFetchRequest.php`、`app/Http/Controllers/V3/Admin/UserController.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：是，需要执行新增 migration，为历史 `blocked_user_ips` 记录补充默认 `type=normal`。
- 回滚说明：回滚新增 migration 移除 `blocked_user_ips.type` 字段，并移除 Service、Request、Controller、测试和文档中的类型参数及邀请码自动解封逻辑即可。

## 2026-07-02 封禁 IP 类型更新接口
- 日期：2026-07-02
- 变更摘要：V3 管理端新增 `POST /user/blockedIp/updateType`，支持按封禁 IP 记录 ID 将类型更新为 `normal` 或 `dangerous`，并返回更新后的 `id/ip/type`。
- 影响范围：`app/Http/Requests/Admin/BlockedUserIpUpdateTypeRequest.php`、`app/Services/BlockedUserIpService.php`、`app/Http/Controllers/V3/Admin/UserController.php`、`app/Http/Routes/V3/AdminRoute.php`、`tests/Feature/UserIpBanTest.php`、`docs/api/user_api.md`、`version.md`
- 是否需要迁移：否，复用 `blocked_user_ips.type` 字段；依赖上一条封禁 IP 类型迁移。
- 回滚说明：移除新增 Request、Service 方法、Controller 方法、路由、测试和文档说明即可。

## 2026-07-02 项目报表部门筛选

- 日期：2026-07-02
- 变更摘要：项目日报查询、项目日报 CSV 导出和项目小时报表查询新增 `filters.departments` 筛选，按 `project_projects.department` 过滤项目范围；查询缓存 key 升级到 `v9`，避免旧缓存缺少部门筛选口径。
- 影响范围：`app/Http/Requests/Admin/ProjectAggregateDailyQueryRequest.php`、`app/Http/Requests/Admin/ProjectAggregateDailyExportRequest.php`、`app/Http/Requests/Admin/ProjectReportHourlyQueryRequest.php`、`app/Services/ProjectReportService.php`、`docs/api/project_report_query_api.md`、`docs/api/project_report_hourly_api.md`、`docs/api/application_route_api.md`、`version.md`
- 是否需要迁移：否，复用既有 `project_projects.department` 字段。
- 回滚说明：移除 Request 中 `filters.departments` 校验、Service 部门过滤逻辑和文档说明，并将项目报表查询缓存 key 恢复到上一版本即可。

## 2026-07-02 项目部门枚举缓存

- 日期：2026-07-02
- 变更摘要：项目管理部门列表接口 `GET /api/v3/admin/{securePath}/projects/departments` 改为读取缓存后的部门枚举列表，缓存 300 秒；创建/编辑项目、批量保存项目、批量更新部门时自动清理部门缓存，保证后续查询刷新。
- 影响范围：`app/Services/ProjectService.php`、`docs/api/project_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除 `ProjectService::departments()` 的缓存读取和部门写入后的缓存清理逻辑，并恢复项目 API 文档中的缓存说明即可。

## 2026-07-02 项目代号枚举缓存

- 日期：2026-07-02
- 变更摘要：项目管理新增 `GET /api/v3/admin/{securePath}/projects/project-codes` 项目代号枚举接口，从 `project_projects.project_code` 查询非空项目代号并缓存 300 秒；创建项目、批量保存新增项目时自动清理项目代号缓存。
- 影响范围：`app/Services/ProjectService.php`、`app/Http/Controllers/V3/Admin/Project/ProjectController.php`、`app/Http/Routes/V3/AdminRoute.php`、`docs/api/project_api.md`、`version.md`
- 是否需要迁移：否，无数据库结构变更。
- 回滚说明：移除新增 `ProjectController::projectCodes()`、路由、`ProjectService::projectCodes()` 及项目代号缓存清理逻辑，并恢复项目 API 文档即可。
