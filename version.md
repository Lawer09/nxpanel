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
