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

- 新建 `docs/api/project.md`：项目接口文档

**迁移说明**：
- 旧的 PUT/PATCH/DELETE 路由不再可用
- 前端需更新请求路径和参数传递方式（id 从 URL 路径移到请求体/查询参数）
- 无需数据库迁移
- 无需回滚

## 2026-05-14

### Firebase Analytics 后端设计落地

- 新增 Firebase Analytics 管理端查询接口：Dashboard、VPN、测速、API 错误、事件明细与筛选项（app/Http/Routes/V3/AdminRoute.php）
- 新增 Firebase Analytics 控制器与 Service，实现统一查询/聚合/趋势/排行/明细逻辑（app/Http/Controllers/V3/Admin/FirebaseAnalytics*Controller.php, app/Services/FirebaseAnalyticsService.php）
- 新增 Firebase Analytics 参数校验 FormRequest（app/Http/Requests/Admin/FirebaseAnalytics*Request.php）

### 性能探测聚合错误码截断

- `perf:aggregate` 聚合探测数据时对 `error_code` 超长内容做 64 字符截断，避免写入 v2_node_probe_aggregated 失败
- 影响范围：app/Console/Commands/AggregatePerformanceReports.php
- 迁移说明：无需数据库迁移；无回滚要求
