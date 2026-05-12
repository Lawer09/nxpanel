## 接口命名规范

### 1. 基本原则

接口命名必须遵循以下原则：

- 使用清晰、稳定、可读的业务资源命名
- 优先使用 RESTful 风格
- URL 使用小写英文
- 多个单词使用短横线 `-`
- 不使用下划线 `_`
- 不使用驼峰命名
- 不在 URL 中暴露数据库表名、内部类名、方法名
- 不在 URL 中使用无意义缩写
- 同一模块接口命名必须保持一致

推荐：

```text
GET    /api/users
GET    /api/users/{id}
POST   /api/users
PUT    /api/users/{id}
DELETE /api/users/{id}
POST   /api/users/{id}/freeze
````

不推荐：

```text
GET    /api/getUserList
GET    /api/user_list
GET    /api/user/getById
POST   /api/userFreeze
POST   /api/t_user/add
```

---

### 2. 资源命名规则

接口路径应以“资源”作为主体，而不是以“动作”作为主体。

推荐使用名词：

```text
/api/users
/api/orders
/api/ad-accounts
/api/system-configs
/api/node-reports
```

不推荐使用动词开头：

```text
/api/get-users
/api/create-order
/api/update-config
/api/query-node-report
```

说明：

* 列表、详情、创建、更新、删除通过 HTTP Method 表达
* URL 表达资源
* 动作只在特殊业务操作中出现

---

### 3. HTTP Method 使用规范

常规 CRUD 接口必须按以下方式命名：

| 操作   | Method | Path                    |
| ---- | ------ | ----------------------- |
| 列表   | GET    | `/api/{resources}`      |
| 详情   | GET    | `/api/{resources}/{id}` |
| 创建   | POST   | `/api/{resources}`      |
| 全量更新 | PUT    | `/api/{resources}/{id}` |
| 局部更新 | PATCH  | `/api/{resources}/{id}` |
| 删除   | DELETE | `/api/{resources}/{id}` |

示例：

```text
GET    /api/users
GET    /api/users/1001
POST   /api/users
PUT    /api/users/1001
PATCH  /api/users/1001
DELETE /api/users/1001
```

---

### 4. 业务动作接口命名

对于无法用标准 CRUD 表达的业务动作，可以在资源后追加动作。

推荐格式：

```text
POST /api/{resources}/{id}/{action}
POST /api/{resources}/{action}
```

示例：

```text
POST /api/users/{id}/freeze
POST /api/users/{id}/unfreeze
POST /api/orders/{id}/cancel
POST /api/orders/{id}/approve
POST /api/ad-accounts/{id}/sync
POST /api/tasks/{id}/retry
```

动作名要求：

* 使用小写英文
* 多个单词使用短横线
* 使用明确动词
* 不使用模糊词

推荐：

```text
/freeze
/unfreeze
/approve
/reject
/cancel
/retry
/sync
/export
/import
/enable
/disable
```

不推荐：

```text
/do
/action
/handle
/operate
/update-status
/change
/submit-data
```

---

### 5. 查询接口命名

查询类接口应优先使用 `GET + 查询参数`。

推荐：

```text
GET /api/users?status=active&page=1&page_size=20
GET /api/orders?start_date=2026-05-01&end_date=2026-05-12
GET /api/node-reports?country=RU&node_id=1001
```

不推荐：

```text
POST /api/users/search
POST /api/get-user-list
GET  /api/queryUsers
```

如果查询条件非常复杂，例如大量筛选条件、复杂嵌套条件、长数组参数，可以使用：

```text
POST /api/{resources}/search
```

示例：

```text
POST /api/reports/search
POST /api/ad-reports/search
```

但必须在接口文档中说明为什么不能使用普通 GET 查询参数。

---

### 6. 导入导出接口命名

导出接口推荐：

```text
GET  /api/{resources}/export
POST /api/{resources}/export
```

使用规则：

* 简单筛选导出可用 `GET`
* 复杂筛选、大量条件导出使用 `POST`
* 如果导出是异步任务，建议使用任务资源表达

示例：

```text
POST /api/users/export
POST /api/orders/export
POST /api/ad-reports/export
POST /api/export-tasks
GET  /api/export-tasks/{id}
GET  /api/export-tasks/{id}/download
```

导入接口推荐：

```text
POST /api/{resources}/import
```

示例：

```text
POST /api/users/import
POST /api/ad-accounts/import
```

---

### 7. 批量操作接口命名

批量操作使用 `batch-{action}`。

推荐：

```text
POST /api/users/batch-delete
POST /api/users/batch-enable
POST /api/users/batch-disable
POST /api/ad-accounts/batch-sync
```

请求体中传递 ID 列表：

```json
{
  "ids": [1, 2, 3]
}
```

不推荐：

```text
POST /api/deleteUsers
POST /api/users/delete-all
POST /api/users/multiDelete
```

---

### 8. 状态切换接口命名

状态切换应使用明确动作，不建议使用泛化的 `update-status`。

推荐：

```text
POST /api/users/{id}/enable
POST /api/users/{id}/disable
POST /api/users/{id}/freeze
POST /api/users/{id}/unfreeze
POST /api/orders/{id}/approve
POST /api/orders/{id}/reject
```

不推荐：

```text
POST /api/users/{id}/update-status
POST /api/users/{id}/change-status
POST /api/users/{id}/status
```

如果状态非常多且是标准字段修改，可以使用：

```text
PATCH /api/users/{id}
```

请求体：

```json
{
  "status": "disabled"
}
```

---

### 9. 嵌套资源命名

存在明确父子关系时，可以使用嵌套资源。

推荐：

```text
GET  /api/users/{user_id}/orders
GET  /api/projects/{project_id}/members
POST /api/projects/{project_id}/members
DELETE /api/projects/{project_id}/members/{member_id}
```

嵌套不应超过两层。

不推荐：

```text
GET /api/companies/{company_id}/projects/{project_id}/users/{user_id}/orders
```

层级过深时，应改为查询参数：

```text
GET /api/orders?company_id=1&project_id=2&user_id=3
```

---

### 10. 参数命名规范

接口参数统一使用小写蛇形命名 `snake_case`。

推荐：

```text
user_id
page_size
start_date
end_date
created_at
updated_at
ad_account_id
node_id
```

不推荐：

```text
userId
pageSize
startDate
adAccountId
NodeID
```

分页参数统一使用：

```text
page
page_size
```

排序参数推荐：

```text
sort_by
sort_order
```

示例：

```text
GET /api/users?page=1&page_size=20&sort_by=created_at&sort_order=desc
```

时间范围参数推荐：

```text
start_date
end_date
```

或：

```text
start_time
end_time
```

同一项目内必须统一。

---

### 11. 返回字段命名规范

API 返回 JSON 字段统一使用 `snake_case`。

推荐：

```json
{
  "user_id": 1001,
  "user_name": "Oliver",
  "created_at": "2026-05-12 10:00:00"
}
```

不推荐：

```json
{
  "userId": 1001,
  "userName": "Oliver",
  "createdAt": "2026-05-12 10:00:00"
}
```

如果前端框架需要 camelCase，应在前端 service 层统一转换，不要让后端同一项目出现多种返回风格。

---

### 12. 管理后台接口命名

如果项目同时存在用户端和管理端接口，应明确前缀。

推荐：

```text
/api/admin/users
/api/admin/orders
/api/admin/ad-accounts
```

用户端：

```text
/api/user/profile
/api/user/orders
```

或：

```text
/api/me
/api/me/orders
```

不要混用：

```text
/api/users/admin-list
/api/getAdminUsers
/api/backend/users
```

---

### 13. 版本号命名

如果项目需要 API 版本号，统一放在 `/api/v1` 后。

推荐：

```text
/api/v1/users
/api/v1/orders
/api/v1/ad-accounts
```

不推荐：

```text
/v1/api/users
/api/users/v1
/api/user/v1/list
```

如果当前项目已有版本规范，必须沿用现有规范，不得擅自新增或变更版本前缀。

---

### 14. Laravel 路由命名规范

Laravel route name 使用点号分隔，按模块和动作命名。

推荐：

```php
Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show');
Route::post('/users', [UserController::class, 'store'])->name('users.store');
Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
Route::post('/users/{id}/freeze', [UserController::class, 'freeze'])->name('users.freeze');
```

管理端接口：

```php
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
});
```

---

### 15. Controller 方法命名规范

标准 CRUD 使用 Laravel 常见方法名：

```text
index
show
store
update
destroy
```

业务动作使用明确动词：

```text
freeze
unfreeze
approve
reject
cancel
retry
sync
export
import
enable
disable
```

不推荐：

```text
getList
getDetail
add
edit
del
doAction
handle
changeStatus
```

---

### 16. 接口文档命名与接口命名关系

`docs/api` 文档按业务模块命名，不按单个接口命名。

示例：

```text
docs/api/user.md
docs/api/order.md
docs/api/ad_account.md
docs/api/node_report.md
```

接口路径使用短横线：

```text
/api/ad-accounts
/api/node-reports
```

文档文件名使用下划线：

```text
docs/api/ad_account.md
docs/api/node_report.md
```

也就是说：

* URL path：使用 `kebab-case`
* 请求参数：使用 `snake_case`
* 返回字段：使用 `snake_case`
* 文档文件：使用 `snake_case`
* Laravel route name：使用 `dot.case`

---

### 17. Agent 执行要求

新增或修改接口时，Agent 必须检查：

1. 接口路径是否符合 RESTful 资源命名
2. HTTP Method 是否正确
3. 参数命名是否统一为 `snake_case`
4. 返回字段是否统一为 `snake_case`
5. Laravel Controller 方法名是否符合规范
6. Laravel route name 是否符合规范
7. 是否需要更新 `docs/api`
8. 是否需要更新 `version.md`

禁止 Agent 在未说明原因的情况下新增以下接口形式：

```text
/api/getXxx
/api/queryXxx
/api/xxx_list
/api/xxxAdd
/api/doSomething
/api/handle
```