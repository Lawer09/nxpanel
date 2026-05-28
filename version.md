# 版本日志

## 2026-05-13

### 项目接口规范化重构

按照 `docs/api_define.md` 新规范重构项目相关接口。

**变更内容**：
- 移除所有 PUT/PATCH/DELETE 路由，统一改为 POST
- 路由 URL 改为 kebab-case 资源/动作风格
- Controller 方法名改为语义化命名：`fetch→index`, `save→store`, `drop→destroy`
- 将路径参数（`{id}`, `{relationId}`）改为请求体/查询参数传递

**影响范围**：
- `ProjectController`
- `ProjectTrafficAccountController`
- `ProjectAdAccountController`
- `ProjectUserAppMapController`
- `ProjectAggregateController`
- `AdSpendPlatformController::projectDaily`

**路由对照表**：

| 旧路由 | 新路由 | 方法 |
|--------|--------|------|
| `GET /projects` | `GET /projects` | index |
| `GET /projects/{id}` | `GET /projects/detail?id=` | detail |
| `POST /projects` | `POST /projects/create` | store |
| `PUT /projects/{id}` | `POST /projects/update` | update |
| `PATCH /projects/{id}/status` | `POST /projects/update-status` | updateStatus |
| `GET /projects/{id}/traffic-accounts` | `GET /projects/traffic-accounts?project_id=` | index |
| `POST /projects/{id}/traffic-accounts` | `POST /projects/traffic-accounts/create` | store |
| `PUT /projects/{id}/traffic-accounts/{id}` | `POST /projects/traffic-accounts/update` | update |
| `DELETE /projects/{id}/traffic-accounts/{id}` | `POST /projects/traffic-accounts/delete` | destroy |
| （同上模式）ad-accounts / user-apps | - | - |
| `GET /projects/{projectCode}/ad-spend-daily` | `GET /projects/ad-spend-daily?project_code=` | projectDaily |
| `POST /report/project/query` | `POST /project-aggregates/query` | queryDaily |
| `GET /project-aggregates/daily` | `POST /project-aggregates/daily` | daily |

### Controller 分层规范修正

- 新建 `ProjectTrafficAccountService`、`ProjectAdAccountService`、`ProjectUserAppMapService`
- 重构 `ProjectTrafficAccountController`、`ProjectAdAccountController`、`ProjectUserAppMapController`：
  - 业务逻辑移入 Service 层
  - Controller 仅负责 Form Request 校验 → Service 调用 → 返回响应
  - delete 操作复用通用 `ProjectResourceIdRequest`（校验 id + projectId）

### 通用 FormRequest 抽离

- 新建 `IdRequest`：通用单 id 校验
- 新建 `ProjectResourceIdRequest`：项目资源操作 id + projectId 校验（delete 操作复用）
- 新建 6 个模块专用 FormRequest，彻底消除 Controller 中的 `$request->validate()`：
  - `ProjectTrafficAccountStoreRequest` / `ProjectTrafficAccountUpdateRequest`
  - `ProjectAdAccountStoreRequest` / `ProjectAdAccountUpdateRequest`
  - `ProjectUserAppMapStoreRequest` / `ProjectUserAppMapUpdateRequest`
- 三个 Controller 的 `store`/`update`/`destroy` 方法全部改为 FormRequest 注入

### 文档

- 新建 `docs/api/project.md`：项目接口文档（后更名为 `docs/api/project_api.md`）

**迁移说明**：
- 旧的 PUT/PATCH/DELETE 路由不再可用
- 前端需更新请求路径和参数传递方式（id 从 URL 路径移到请求体/查询参数）
- 无需数据库迁移
- 无需回滚

## 2026-05-15

### Project 基础 CRUD 接口文档
- 新建 `docs/api/project_api.md`：涵盖列表（GET /）、详情（GET /detail）、创建（POST /create）、编辑（POST /update）、更新状态（POST /update-status），共 5 个 endpoint

### 修复
- 修复 `ProjectUpdateRequest` 中 `messages()` 方法重复定义的问题

### 影响范围
- `docs/api/project_api.md`
- `app/Http/Requests/Admin/ProjectUpdateRequest.php`

### Firebase Analytics 后端设计落地

- 新增 Firebase Analytics 管理端查询接口：Dashboard、VPN、测速、API 错误、事件明细与筛选项（app/Http/Routes/V3/AdminRoute.php）
- 新增 Firebase Analytics 控制器与 Service，实现统一查询/聚合/趋势/排行/明细逻辑（app/Http/Controllers/V3/Admin/FirebaseAnalytics*Controller.php, app/Services/FirebaseAnalyticsService.php）
- 新增 Firebase Analytics 参数校验 FormRequest（app/Http/Requests/Admin/FirebaseAnalytics*Request.php）

### 性能探测聚合错误码截断

- `perf:aggregate` 聚合探测数据时对 `error_code` 超长内容做 64 字符截断，避免写入 v2_node_probe_aggregated 失败
- 影响范围：app/Console/Commands/AggregatePerformanceReports.php
- 迁移说明：无需数据库迁移；无回滚要求

### Ad Spend 定时拉取 token Redis 缓存

- `ad-spend:sync` 定时拉取流程登录 token 新增 Redis 缓存：key `ad_spend:platform:token:{accountId}`，TTL 固定 1 小时
- 拉取报表时优先读取 Redis token；Redis 失效后自动重新登录并写回 Redis，再继续拉取
- 登录接口未返回过期时间时，统一按 1 小时写入数据库 `token_expired_at`，保持数据库与 Redis 过期策略一致

### 影响范围

- `app/Services/AdSpendPlatformService.php`

### 迁移说明

- 无需数据库迁移
- 无需回滚

## 2026-05-25

### loginByAid 增加默认多次使用邀请码

- 在 `LoginService::loginByAid` 的自动注册分支中，新增邀请码创建逻辑
- 新建用户后自动确保存在一个 `MU-` 前缀邀请码（默认可多次使用）
- 邀请码消费逻辑调整：
  - `RegisterService::handleInviteCode` 对 `MU-` 前缀邀请码不置为已使用
  - `InviteService::useCode` 对 `MU-` 前缀邀请码不置为已使用

### 影响范围

- `app/Services/Auth/LoginService.php`
- `app/Services/Auth/RegisterService.php`
- `app/Services/InviteService.php`

### 迁移说明

- 无需数据库迁移
- 无需回滚

## 2026-05-25

### 新增 V3 邀请码使用接口（注册后补填）

- 新增接口：`POST /api/v3/user/invite-codes/use`
- 新增请求校验：`app/Http/Requests/User/InviteCodeUseRequest.php`
- 新增服务逻辑：`InviteService::useCode()`，包含事务与规则校验：
  - 仅允许未绑定邀请人的用户使用
  - 邀请码必须存在且为未使用状态
  - 禁止使用自己的邀请码
  - 按 `invite_never_expire` 配置决定是否置邀请码为已使用
- 更新路由：`app/Http/Routes/V3/UserRoute.php`
- 更新文档：`docs/api/user_api.md`

### 影响范围

- `app/Http/Controllers/V3/User/InviteController.php`
- `app/Http/Requests/User/InviteCodeUseRequest.php`
- `app/Services/InviteService.php`
- `app/Http/Routes/V3/UserRoute.php`
- `docs/api/user_api.md`

### 迁移说明

- 无需数据库迁移
- 无需回滚

## 2026-05-25

### V3 邀请接口分层修正（Controller + FormRequest + Service）

- 修正 `app/Http/Controllers/V3/User/InviteController.php`：
  - 不再直接使用通用 `Request`
  - 不在 Controller 内承载大量业务逻辑
  - 仅保留请求接收、参数校验、Service 调用与统一响应
- 新增请求校验类：
  - `app/Http/Requests/User/InviteCodeCreateRequest.php`
  - `app/Http/Requests/User/InviteSummaryRequest.php`
  - `app/Http/Requests/User/InviteCommissionListRequest.php`
- 新增业务服务：`app/Services/InviteService.php`
  - `createCode()` 处理邀请码创建逻辑
  - `summary()` 处理邀请统计聚合逻辑
  - `commissions()` 处理返佣分页查询逻辑

### 影响范围

- `app/Http/Controllers/V3/User/InviteController.php`
- `app/Http/Requests/User/InviteCodeCreateRequest.php`
- `app/Http/Requests/User/InviteSummaryRequest.php`
- `app/Http/Requests/User/InviteCommissionListRequest.php`
- `app/Services/InviteService.php`

### 迁移说明

- 无需数据库迁移
- 无需回滚

## 2026-05-25

### V3 邀请分页参数收敛

- `GET /api/v3/user/invite/commissions` 移除旧参数兼容，仅支持 `page`、`pageSize`
- 更新 `InviteCommissionListRequest` 校验规则，删除 `current`、`page_size` 校验
- 更新 `InviteController::commissions` 参数读取逻辑，不再回退旧字段
- 同步更新 `docs/api/user_api.md` 的 V3 参数说明

### 影响范围

- `app/Http/Requests/User/InviteCommissionListRequest.php`
- `app/Http/Controllers/V3/User/InviteController.php`
- `docs/api/user_api.md`

### 迁移说明

- 无需数据库迁移
- 无需回滚

## 2026-05-25

### 邀请接口升级为 V3（含分页）

- 新增 V3 用户邀请控制器：`app/Http/Controllers/V3/User/InviteController.php`
- 新增 V3 路由：
  - `POST /api/v3/user/invite-codes/create`（生成邀请码）
  - `GET /api/v3/user/invite/summary`（邀请统计，返回对象结构）
  - `GET /api/v3/user/invite/commissions`（返佣明细分页）
- 新增分页参数校验：`app/Http/Requests/User/InviteCommissionListRequest.php`
- 返佣明细接口统一分页返回：`data`、`total`、`page`、`pageSize`

### 文档

- 更新 `docs/api/user_api.md`，新增 V3 邀请接口章节
- 保留 V1 邀请接口说明并标注迁移建议

### 影响范围

- `app/Http/Controllers/V3/User/InviteController.php`
- `app/Http/Routes/V3/UserRoute.php`
- `app/Http/Requests/User/InviteCommissionListRequest.php`
- `docs/api/user_api.md`

### 迁移说明

- 无需数据库迁移
- 无需回滚

## 2026-05-25

### 用户邀请接口文档补充

- 在 `docs/api/user_api.md` 新增“邀请相关接口（V1）”章节
- 补充以下接口文档：
  - `GET /api/v1/user/invite/save`（生成邀请码）
  - `GET /api/v1/user/invite/fetch`（邀请统计）
  - `GET /api/v1/user/invite/details`（返佣明细）
- 明确 `fetch` 的 `stat` 数组顺序含义与 `details` 的分页参数

### 影响范围

- `docs/api/user_api.md`

### 迁移说明

- 无需数据库迁移
- 无需回滚

### Automation 模块注册改为一次性注入

- `AutomationServiceProvider` 调整为在 `register()` 中一次性构造 `AutomationModuleRegistry` 并注入 handlers（当前包含 `TrafficPlatformAutomationService`）
- 移除 `boot()` 中的二次注册流程，避免重复注册与初始化路径分散
- 同步更新自动化开发说明文档，明确推荐“Provider register 阶段一次性注入 handlers”

### 影响范围

- `app/Providers/AutomationServiceProvider.php`
- `docs/components/automation_rule_development_guide.md`

### 迁移说明

- 无需数据库迁移
- 配置/代码更新后需重启应用进程（如 Octane/Horizon）

### 自动化 run 未命中时补充 skipped 执行记录

- 修复 `automation-rules/run` 在条件未命中且无恢复动作时不写执行记录的问题
- `TrafficPlatformAutomationService` 现会在该分支写入 `skipped` 状态执行日志（`reason=condition_not_matched` 或 `recovery_disabled`）
- 便于 `automation-rules/executions` 观察到“已执行但未触发动作”的结果，并与返回汇总中的 `skippedCount` 对齐

### 影响范围

- `app/Services/Automation/TrafficPlatformAutomationService.php`

### 迁移说明

- 无需数据库迁移
- 需要重启应用进程（如 Octane/Horizon）使代码变更生效

### 自动化 run 返回增加实际命中规则/目标明细

- `automation-rules/run` 返回新增 `ruleIds`、`targetIds` 字段
- 用于明确本次执行实际命中的规则与目标，便于排查“同一时刻多条执行记录”来源
- 同步更新 `docs/api/automation_rules_api.md` 的 run 返回字段说明

### 影响范围

- `app/Services/Automation/TrafficPlatformAutomationService.php`
- `docs/api/automation_rules_api.md`

### 迁移说明

- 无需数据库迁移
- 需要重启应用进程（如 Octane/Horizon）使代码变更生效

### AutomationServiceProvider boot 改回参数注入

- `AutomationServiceProvider::boot` 改回参数注入方式：`boot(AutomationModuleRegistry $registry, TrafficPlatformAutomationService $trafficPlatformHandler)`
- 移除 boot 内显式 `make`，保持 Provider 依赖注入风格一致

### 影响范围

- `app/Providers/AutomationServiceProvider.php`

### 迁移说明

- 无需数据库迁移
- 无需回滚

### 自动化模块注册修复（traffic_platform 422）

- 修复 `AutomationModuleRegistry` 在部分启动顺序下未注册 `traffic_platform` 处理器，导致接口报错：`不支持的自动化模块: traffic_platform`
- `AutomationServiceProvider` 改为：
  - `register()` 仅绑定 Registry 单例
  - `boot()` 显式执行 `registerHandler(TrafficPlatformAutomationService)` 完成模块注册
- 保障控制器/服务在解析 Registry 时可稳定获取 `traffic_platform` 模块

### 影响范围

- `app/Providers/AutomationServiceProvider.php`

### 迁移说明

- 无需数据库迁移
- 需要重启应用进程（如 `php-fpm` / Octane / Horizon）使 Provider 变更生效

### AutomationServiceProvider boot 注入方式调整

- 将 `AutomationServiceProvider::boot` 从方法参数注入改为容器内显式 `make` 获取 `AutomationModuleRegistry` 与 `TrafficPlatformAutomationService`
- 保持 `traffic_platform` 模块注册逻辑不变，减少在不同运行时环境下的 boot 参数注入差异影响

### 影响范围

- `app/Providers/AutomationServiceProvider.php`

### 迁移说明

- 无需数据库迁移
- 无需回滚

### 自动化模块注册增加运行时兜底

- 针对部分环境仍出现 `不支持的自动化模块: traffic_platform`，在 `AutomationModuleRegistry::getHandlerOrFail` 增加运行时兜底注册
- 当请求模块为 `traffic_platform` 且当前未注册时，Registry 将尝试通过容器解析 `TrafficPlatformAutomationService` 并即时注册
- 保持对其他模块的行为不变，避免影响现有模块扩展路径

### 影响范围

- `app/Services/Automation/AutomationModuleRegistry.php`

### 迁移说明

- 无需数据库迁移
- 需要重启应用进程（如 `php-fpm` / Octane / Horizon）使新代码生效

### 自动化规则 list/detail 返回结构按 module 分层说明

- 在 `docs/api/automation_rules_api.md` 中补齐规则列表（`GET /`）与规则详情（`GET /detail`）的返回字段说明
- 将返回说明拆分为：
  - 通用返回（所有 module）
  - `module=traffic_platform` 专有返回示例
- 明确 `targetScopeJson`、`conditionsJson`、`actionsJson` 在 `traffic_platform` 下的专有语义，和创建/更新章节保持一致

### 影响范围

- `docs/api/automation_rules_api.md`

### 迁移说明

- 无需数据库迁移
- 无需回滚

### 自动化规则文档按 module 区分结构完善

- 调整 `automation_rules_api.md`：将创建/更新/手动执行/执行记录等接口补充为“通用字段 + module 专有字段”结构
- 明确标注 `module=traffic_platform` 场景下的专有字段：
  - `targetScope.accountIds/platformCodes/includeDisabled`
  - `conditions[].metric` 可用指标集合
  - `actions[].type` 可用动作集合
- 补充执行记录返回字段说明，并说明 `traffic_platform` 模块下 `metricsSnapshot` 的主要字段
- 更新 `automation_rule_development_guide.md`：新增“接口文档编写要求（按 module 区分结构）”规范

### 影响范围

- `docs/api/automation_rules_api.md`
- `docs/components/automation_rule_development_guide.md`

### 迁移说明

- 无需数据库迁移
- 无需回滚

### Traffic Platform 平台列表改为分页返回

- `TrafficPlatformController::index`（`GET /traffic-platform/platforms`）返回结构调整为分页格式
- `TrafficPlatformService::index` 新增分页查询逻辑，支持 `page` / `pageSize`
- `TrafficPlatformIndexRequest` 新增 `page` / `pageSize` 参数校验
- 同步更新 `docs/api/traffic_platform_platforms_api.md` 的请求参数与返回结构说明

### 影响范围

- `app/Http/Controllers/V3/Admin/TrafficPlatform/TrafficPlatformController.php`
- `app/Services/TrafficPlatform/TrafficPlatformService.php`
- `app/Http/Requests/Admin/TrafficPlatformIndexRequest.php`
- `docs/api/traffic_platform_platforms_api.md`

### 迁移说明

- 无需数据库迁移
- 无需回滚

### Traffic Platform 接口文档补充返回字段说明

- 参照 `docs/api/ad_revenue_api.md` 的文档结构，为 `docs/api/traffic_platform_platforms_api.md` 补齐各接口返回字段说明
- 新增平台配置、平台账号、流量查询、同步任务等接口的返回结构示例与字段表（含类型与含义）
- 明确分页接口统一返回 `data` / `total` / `page` / `pageSize`，并补充动态返回接口的字段说明

### 影响范围

- `docs/api/traffic_platform_platforms_api.md`

### 迁移说明

- 无需数据库迁移
- 无需回滚

### Traffic Platform 账号余额字段与接口规范化

- `traffic_platform_accounts` 新增 `balance` 字段（int，单位 MB，默认 0），用于表示账号剩余可用流量
- `TrafficPlatformAccountController` 的账号列表/详情/创建/更新返回与入参支持 `balance`
- 流量平台模块路由与 Controller 方法按 `docs/api_define.md` 规范统一：
  - 路由仅使用 GET/POST
  - URL 改为 `.../detail`、`.../create`、`.../update`、`.../update-status`
  - id 参数从路径参数改为 query/body（如 `GET /accounts/detail?id=`、`POST /accounts/update`）
  - 方法命名统一为 `index/store/update/updateStatus/detail`

### 文档

- 更新 `docs/api/traffic_platform_platforms_api.md`：同步路由规范、`balance` 字段说明与示例

### 影响范围

- `app/Http/Routes/V3/AdminRoute.php`
- `app/Http/Controllers/V3/Admin/TrafficPlatform/TrafficPlatformController.php`
- `app/Http/Controllers/V3/Admin/TrafficPlatform/TrafficPlatformAccountController.php`
- `app/Http/Controllers/V3/Admin/TrafficPlatform/TrafficPlatformSyncController.php`
- `app/Models/TrafficPlatformAccount.php`
- `database/migrations/2026_05_18_000001_add_balance_to_traffic_platform_accounts_table.php`
- `docs/api/traffic_platform_platforms_api.md`

### 迁移说明

- 需要执行 `php artisan migrate` 以增加 `traffic_platform_accounts.balance`
- 回滚可执行 `php artisan migrate:rollback --step=1`

### Traffic Platform 分层与 FormRequest 规范化

- 流量平台模块新增 Service 层并下沉 Controller 业务逻辑：
  - `app/Services/TrafficPlatform/TrafficPlatformService.php`
  - `app/Services/TrafficPlatform/TrafficPlatformAccountService.php`
  - `app/Services/TrafficPlatform/TrafficPlatformSyncService.php`
  - `app/Services/TrafficPlatform/TrafficPlatformUsageService.php`
- 流量平台模块查询/创建/更新/状态变更/触发同步全部改为 FormRequest 注入校验（不再使用 Controller 内 `$request->validate()`）
- `TrafficPlatform*Controller` 仅保留：接收请求 -> 调用 Service -> 返回统一响应

### 影响范围

- `app/Http/Controllers/V3/Admin/TrafficPlatform/*.php`
- `app/Services/TrafficPlatform/*.php`
- `app/Http/Requests/Admin/TrafficPlatform*.php`

### 迁移说明

- 无需新增数据库迁移
- 无需回滚

### SyncServer sync-revenue 支持可选日期范围

- `SyncServerController::syncRevenueByDate` 支持可选 query 参数 `start_date`、`end_date`
- 当传入日期时，管理端转发到节点 `/api/sync/revenue?start_date=...&end_date=...`，并通过 `Authorization` 头携带密钥
- 当未传日期时，允许直接触发节点默认范围同步
- 增加参数约束：日期参数必须成对出现，且格式为 `Y-m-d`

### 文档

- 新增 `docs/api/sync_servers_api.md`，补充 `test-sync` 与 `sync-revenue` 接口说明和 curl 示例

### 影响范围

- `app/Http/Controllers/V3/Admin/SyncServerController.php`
- `docs/api/sync_servers_api.md`

### 迁移说明

- 无需数据库迁移
- 无需回滚

### SyncServer test-sync 鉴权修复

- 修复 `SyncServerController::testSync` 请求 `/api/sync/trigger` 的鉴权方式：由 body 传 `key` 改为请求头 `Authorization`
- 与本地 curl 调用方式保持一致，避免返回 `{"error":"unauthorized"}`

### 影响范围

- `app/Http/Controllers/V3/Admin/SyncServerController.php`

### 迁移说明

- 无需数据库迁移
- 无需回滚

## 2026-05-19

### Traffic Platform �Զ�������붯����ܣ���һ�ڣ�

- �����Զ����������������֧�ְ����������������˺�ָ�겢ִ�и澯���Զ�������
- ��������״̬��������ȴ��ִ����־�������ݽṹ��
  - `automation_rules`
  - `automation_rule_states`
  - `automation_executions`
- ���� `TrafficPlatformAutomationService`��ʵ�֣�
  - Ŀ���˺�ɸѡ����ƽ̨/�˺ŷ�Χ��
  - ָ��ɼ���`balance_mb`���� 1h/6h ������Сʱ��ֵ��Ԥ�ƺľ�ʱ����
  - ����������`all/any` + `eq/neq/gt/gte/lt/lte/in/not_in/between`��
  - ��ȴ���ơ��ָ�֪ͨ�������ַ�
- �������ö�����
  - `telegram_admin`
  - `email`
  - `disable_account`
- �����ֶ�ִ�����������ӿڣ�
  - `GET /traffic-platform/automation-rules`
  - `GET /traffic-platform/automation-rules/detail`
  - `POST /traffic-platform/automation-rules/create`
  - `POST /traffic-platform/automation-rules/update`
  - `POST /traffic-platform/automation-rules/update-status`
  - `POST /traffic-platform/automation-rules/run`
- ������ʱ���� `traffic-platform:automation-run`������ `Kernel` ��ÿ 5 ���ӵ���ִ�С�
- �����ӿ��ĵ���`docs/api/traffic_platform_automation_rules_api.md`��

### Ӱ�췶Χ

- `database/migrations/2026_05_19_000001_create_automation_rule_tables.php`
- `app/Models/AutomationRule.php`
- `app/Models/AutomationRuleState.php`
- `app/Models/AutomationExecution.php`
- `app/Services/Automation/AutomationRuleService.php`
- `app/Services/Automation/TrafficPlatformAutomationService.php`
- `app/Console/Commands/RunTrafficPlatformAutomation.php`
- `app/Console/Kernel.php`
- `app/Http/Controllers/V3/Admin/TrafficPlatform/TrafficPlatformAutomationRuleController.php`
- `app/Http/Requests/Admin/TrafficPlatformAutomationRule*.php`
- `app/Http/Routes/V3/AdminRoute.php`
- `docs/api/traffic_platform_automation_rules_api.md`

### Ǩ��/�ع�˵��

- ��Ҫִ�У�`php artisan migrate`
- �ع���ʽ��`php artisan migrate:rollback --step=1`

### Traffic Platform �Զ���ִ����·��Ϊ Horizon ����ִ��

- `traffic-platform:automation-run` ����Ĭ�ϸ�Ϊ��Ͷ�ݶ�������`automation`������ Horizon worker ����ִ���Զ�����⡢�澯�붯���ַ���
- ������������ `RunTrafficPlatformAutomationJob`��
  - ���У�`automation`
  - ���ԣ�2 ��
  - ��ʱ��300 ��
  - ���ˣ�`[30, 120]`
  - ���ӷֲ�ʽ�� `traffic_platform_automation:run`�����Ⲣ���ظ�ִ�С�
- �������� `--sync` ������������Ҫ���ϻ򱾵ص���ʱͬ��ֱ�ܡ�
- `Kernel` ���ȱ���ÿ 5 ���Ӵ�������ʵ��ִ�н��� Horizon��
- `config/horizon.php` ���� `automation` supervisor������ `automation` ���м��뱾�ػ��������б���

### Ӱ�췶Χ

- `app/Jobs/RunTrafficPlatformAutomationJob.php`
- `app/Console/Commands/RunTrafficPlatformAutomation.php`
- `app/Console/Kernel.php`
- `config/horizon.php`

### Ǩ��/�ع�˵��

- �������ݿ�Ǩ��
- �������� Horizon�������� Horizon ʹ�¶���������Ч��`php artisan horizon:terminate`

### Traffic Platform �Զ���ִ�м�¼��Ϊ Redis�������� 100 ����

- �Զ�������ִ�м�¼д������ݿ��Ϊ Redis �б��洢��
- Redis Key��`automation:executions:traffic_platform`��
- ÿ��д��ִ�� `LPUSH + LTRIM`������������ 100 ����¼��
- ����������ִ�м�¼��ѯ�ӿڣ�`GET /traffic-platform/automation-rules/executions`��
- ��ѯ֧�� `ruleId`��`targetId`��`status`����ҳ�������ˣ��������� 100 ���ڴ���ˣ���

### Ӱ�췶Χ

- `app/Services/Automation/TrafficPlatformAutomationService.php`
- `app/Services/Automation/AutomationExecutionLogService.php`
- `app/Http/Controllers/V3/Admin/TrafficPlatform/TrafficPlatformAutomationRuleController.php`
- `app/Http/Requests/Admin/TrafficPlatformAutomationExecutionIndexRequest.php`
- `app/Http/Routes/V3/AdminRoute.php`
- `docs/api/traffic_platform_automation_rules_api.md`

### Ǩ��/�ع�˵��

- �������ݿ�Ǩ��
- �ɱ� `automation_executions` ��ʷ���ݲ���Ӱ�죨��������·��Ϊ Redis��

### �Զ������򿪷�˵���ĵ�ͬ���� doc Ŀ¼

- �����ĵ�������`doc/automation_rule_development_guide.md`
- ���ں����� `doc` Ŀ¼Լ�������������չ����ʵ�֡�

### Ӱ�췶Χ

- `doc/automation_rule_development_guide.md`

### Ǩ��/�ع�˵��

- �������ݿ�Ǩ��
- ����ع�

### �Զ��������ĵ�·����������

- ɾ�� `doc/automation_rule_development_guide.md`��
- ������ `docs/components/automation_rule_development_guide.md` ��Ϊ���򿪷�˵�����ĵ���

### Ӱ�췶Χ

- `doc/automation_rule_development_guide.md`��ɾ����

### Ǩ��/�ع�˵��

- �������ݿ�Ǩ��
- ����ع�

### �Զ���������ϵ�ع�Ϊͨ��ģ����ڣ��Ƴ�ģ��Ӳ�󶨣�

- �Ƴ� `traffic-platform` ר���Զ���������ڣ���Ϊͳһ API ǰ׺��`/automation-rules`��ͨ�� `module` ��������ģ�顣
- ����ͨ�ÿ�������`AutomationRuleController`��ͳһ�ṩ�б������顢���������¡���ͣ��ִ�С�ִ�м�¼�ӿڡ�
- �ع� `AutomationRuleService`��
  - ɾ���̶�ģ�鳣����
  - ��Ϊ�� `module` ��ѯ����¹���
  - `store/update` ֧��ͨ��ģ�����
- ����ģ��ע����ִ�г���
  - `AutomationModuleHandler` �ӿ�
  - `AutomationModuleRegistry` ע������
  - `AutomationRunnerService` ģ��ִ�����
- `TrafficPlatformAutomationService` ����Ϊģ�鴦����ʵ�֣�`moduleKey=traffic_platform`������������ RuleService ģ�鳣����
- ����������ع�Ϊͨ����ڣ�
  - �����`automation:run {module}`
  - ������`RunAutomationJob`
  - ���ȸ�Ϊ��`automation:run traffic_platform`
- ִ�м�¼�ӿڸ�Ϊͨ�ò�ѯ��`GET /automation-rules/executions?module=...`��Redis Key �淶��Ϊ `automation:executions:{module}`��
- ɾ���ɵ�ר�ÿ������������ࡢ����������񼰾� API �ĵ���
- �ĵ������������� `docs/components/automation_rule_development_guide.md`��������Ϊͨ��ģ���˵����

### Ӱ�췶Χ

- `app/Http/Controllers/V3/Admin/AutomationRuleController.php`
- `app/Http/Requests/Admin/Automation*.php`
- `app/Services/Automation/AutomationRuleService.php`
- `app/Services/Automation/AutomationRunnerService.php`
- `app/Services/Automation/AutomationModuleRegistry.php`
- `app/Services/Automation/Contracts/AutomationModuleHandler.php`
- `app/Services/Automation/TrafficPlatformAutomationService.php`
- `app/Jobs/RunAutomationJob.php`
- `app/Console/Commands/RunAutomation.php`
- `app/Http/Routes/V3/AdminRoute.php`
- `app/Console/Kernel.php`
- `app/Providers/AutomationServiceProvider.php`
- `config/app.php`
- `docs/api/automation_rules_api.md`
- `docs/components/automation_rule_development_guide.md`

### Ǩ��/�ع�˵��

- �������ݿ�Ǩ��
- �� Horizon �������У�ִ�� `php artisan horizon:terminate` ʹ�������������·��Ч

### 自动化策略模块 model 查询接口与 Registry 注入错误修复（2026-05-19）

- 新增策略 model 查询接口：`GET /automation-rules/models?module=...`，按模块返回可配置的 model 标识列表。
- 扩展 `AutomationModuleHandler` 接口，新增 `supportedModels()`，由各模块处理器声明可用 model。
- `TrafficPlatformAutomationService` 补充 `supportedModels()` 实现，当前返回 `traffic_platform_account`。
- 修复 `AutomationModuleRegistry` 容器解析异常：
  - 构造函数改为可无参解析（默认空 handlers）
  - 增加 `registerHandlers()` / `registerHandler()` 显式注册
  - 增加 `normalizeModule()` 统一模块名格式
- 模块名兼容增强：统一支持 `traffic-platform` 与 `traffic_platform`。
- 执行记录与规则服务统一做模块标准化，避免同模块多键或查询不到数据。
- 同步更新自动化 API 文档与组件开发文档。

### 影响范围

- `app/Http/Controllers/V3/Admin/AutomationRuleController.php`
- `app/Http/Requests/Admin/AutomationModelIndexRequest.php`
- `app/Http/Routes/V3/AdminRoute.php`
- `app/Services/Automation/Contracts/AutomationModuleHandler.php`
- `app/Services/Automation/AutomationModuleRegistry.php`
- `app/Services/Automation/AutomationRuleService.php`
- `app/Services/Automation/AutomationRunnerService.php`
- `app/Services/Automation/AutomationExecutionLogService.php`
- `app/Services/Automation/TrafficPlatformAutomationService.php`
- `app/Jobs/RunAutomationJob.php`
- `app/Providers/AutomationServiceProvider.php`
- `docs/api/automation_rules_api.md`
- `docs/components/automation_rule_development_guide.md`

### 迁移/回滚说明

- 无需数据库迁移。

## 2026-05-28

### v2_server 节点限速与设备限制字段入库

- 为 `v2_server` 新增字段：
  - `rate_limit`：节点速率限制，单位 `bytes/s`，默认 `0`（表示不限制）
  - `device_limit`：节点设备限制，默认 `0`（表示不限制）
- 管理端节点保存参数新增校验：`rate_limit`、`device_limit`（整数，最小值 0）
- 由于 `app/Http/Controllers/V3/Admin/Server/ManageController.php` 继承 `V2` 保存逻辑，V2/V3 节点保存链路均支持以上两个字段持久化

### 影响范围

- `database/migrations/2026_05_28_120000_add_rate_limit_and_device_limit_to_v2_server_table.php`
- `app/Http/Requests/Admin/ServerSave.php`

### 迁移/回滚说明

- 执行迁移：`php artisan migrate`
- 回滚该迁移：`php artisan migrate:rollback --path=database/migrations/2026_05_28_120000_add_rate_limit_and_device_limit_to_v2_server_table.php`

## 2026-05-28

### DNS 迁移重复索引容错修复

- 修复 `2026_05_20_000002_alter_dns_ip_bindings_add_record_fields` 在部分环境执行时创建索引报错：`Duplicate key name 'uk_provider_domain_remote_key'`
- 调整为在创建前查询 `information_schema.statistics`，仅当索引不存在时再创建：
  - `uk_provider_domain_remote_key`
  - `idx_provider_record`

### 影响范围

- `database/migrations/2026_05_20_000002_alter_dns_ip_bindings_add_record_fields.php`

### 迁移/回滚说明

- 重新执行：`php artisan migrate`
- 无需额外数据迁移；仅修正迁移执行幂等性

### dns_ip_bindings 表结构升级（V3，2026-05-20）

- 新增迁移：`database/migrations/2026_05_20_000002_alter_dns_ip_bindings_add_record_fields.php`
- `dns_ip_bindings` 新增字段：
  - `record_name`
  - `record_type`
  - `proxied`
  - `raw_record`
  - `remote_key`
  - `synced_at`
  - `released_at`
- 新增索引：
  - 唯一索引 `uk_provider_domain_remote_key(provider_account_id, domain_id, remote_key)`
  - 普通索引 `idx_provider_record(provider_account_id, remote_record_id)`
- 同步更新 `DnsIpBinding` 模型 casts 与 DNS API 文档。

### 影响范围

- `database/migrations/2026_05_20_000002_alter_dns_ip_bindings_add_record_fields.php`
- `app/Models/DnsIpBinding.php`
- `docs/api/dns_api.md`

### 迁移/回滚说明

- 执行迁移：`php artisan migrate`
- 回滚该迁移：`php artisan migrate:rollback --path=database/migrations/2026_05_20_000002_alter_dns_ip_bindings_add_record_fields.php`

### Enum 新增 AppID 枚举接口（V3，2026-05-20）

- 新增接口：`GET /enum/app-ids`
- 来源：`project_user_app_map.app_id`
- 数据处理：去重、去空值，支持 `keyword` 模糊过滤
- 返回结构：`appId` / `value` / `label`
- 新增参数校验 `EnumAppIdsRequest`
- 同步新增文档 `docs/api/enum_api.md`

### 影响范围

- `app/Http/Controllers/V3/Admin/EnumController.php`
- `app/Http/Requests/Admin/EnumAppIdsRequest.php`
- `app/Http/Routes/V3/AdminRoute.php`
- `docs/api/enum_api.md`

### 迁移/回滚说明

- 无需数据库迁移。
- 若线上使用 `config:cache`，发布后执行 `php artisan optimize:clear` 或重新构建缓存，确保新 Provider 绑定与路由生效。
- 若 Horizon 正在运行，执行 `php artisan horizon:terminate` 滚动加载新代码。

### DNS 工具接口重构（V3，2026-05-19）

- 重构 `V3/Admin/DnsToolController` 为 Controller + FormRequest + Service 分层，移除 Controller 内联校验。
- 新增本地库 DNS 管理接口：
  - Provider：`GET /dns/providers`、`GET /dns/providers/detail`、`POST /dns/providers/create`、`POST /dns/providers/update`
  - Provider Account：`GET /dns/provider-accounts`、`GET /dns/provider-accounts/detail`、`POST /dns/provider-accounts/create`、`POST /dns/provider-accounts/update`
  - Domain：`GET /dns/domains`、`POST /dns/domains/update-meta`
  - IP Binding：`GET /dns/ip-bindings`、`GET /dns/records/by-ip`、`POST /dns/ip-bindings/update-meta`
- 外部调用接口保留：`POST /dns/records/resolve`、`POST /dns/records/unbind`。
- `by-ip` 查询改为本地库读取 `dns_ip_bindings`，不再依赖外部 DNS 服务。
- 写入权限约束落地：
  - 允许新增/更新：`dns_provider`、`dns_provider_accounts`
  - 仅允许更新 `note`/`tags`：`dns_domains`、`dns_ip_bindings`
- 新增 DNS 相关模型与管理服务：`DnsProvider`、`DnsProviderAccount`、`DnsDomain`、`DnsIpBinding`、`DnsAdminService`。
- 新增接口文档：`docs/api/dns_api.md`。

### 影响范围

- `app/Http/Controllers/V3/Admin/DnsToolController.php`
- `app/Http/Routes/V3/AdminRoute.php`
- `app/Services/Dns/DnsAdminService.php`
- `app/Models/DnsProvider.php`
- `app/Models/DnsProviderAccount.php`
- `app/Models/DnsDomain.php`
- `app/Models/DnsIpBinding.php`
- `app/Http/Requests/Admin/Dns*.php`（新增 DNS 专用 FormRequest）
- `docs/api/dns_api.md`

### 迁移/回滚说明

- 无需数据库迁移。
- 回滚时可恢复旧 DNS 路由与旧 Controller 逻辑（不影响表结构）。

### DNS 表迁移补充（V3，2026-05-20）

- 新增 DNS 相关迁移文件：`database/migrations/2026_05_20_000001_create_dns_tool_tables.php`。
- 迁移将按“表不存在才创建”的方式补齐以下 4 张表：
  - `dns_provider`
  - `dns_provider_accounts`
  - `dns_domains`
  - `dns_ip_bindings`
- 约束与索引与 `schema.sql` 保持一致（含唯一索引、复合索引、外键）。

### 影响范围

- `database/migrations/2026_05_20_000001_create_dns_tool_tables.php`

### 迁移/回滚说明

- 执行迁移：`php artisan migrate`
- 回滚该迁移：`php artisan migrate:rollback --path=database/migrations/2026_05_20_000001_create_dns_tool_tables.php`

### DNS 域名列表返回增强（V3，2026-05-20）

- `GET /dns/domains` 返回新增：
  - `accountName`：域名所属 Provider 账号名称（来自 `dns_provider_accounts.account_name`）
  - `bindingIpCount`：当前域名 `active` 状态绑定 IP 数（来自 `dns_ip_bindings` 聚合）
- 同步更新文档字段说明。

### 影响范围

- `app/Services/Dns/DnsAdminService.php`
- `docs/api/dns_api.md`

### 迁移/回滚说明

- 无需数据库迁移。
