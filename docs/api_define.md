## 接口命名规范

### 1. 基本原则

本项目接口仅允许使用以下 HTTP Method：

- `GET`
- `POST`

禁止使用：

- `PUT`
- `PATCH`
- `DELETE`

所有新增、修改、删除、批量操作、业务动作类接口统一使用 `POST`。

查询类接口根据查询条件复杂度选择 `GET` 或 `POST`。

---

### 2. URL 命名原则

接口路径必须遵循以下原则：

- 使用清晰、稳定、可读的业务资源命名
- URL 使用小写英文
- 多个单词使用短横线 `-`
- 不使用下划线 `_`
- 不使用驼峰命名
- 不在 URL 中暴露数据库表名、内部类名、方法名
- 不使用无意义缩写
- 同一模块接口命名必须保持一致

推荐：

```text
GET  /api/users
GET  /api/users/detail
POST /api/users/create
POST /api/users/update
POST /api/users/delete
POST /api/users/freeze
````

不推荐：

```text
GET  /api/getUserList
GET  /api/user_list
GET  /api/user/getById
POST /api/userFreeze
POST /api/t_user/add
```

---

### 3. 查询接口命名规则

#### 3.1 简单查询使用 GET

当查询条件简单，且不包含数组、复杂对象、大量筛选条件时，可以使用 `GET`。

推荐：

```text
GET /api/users
GET /api/users/detail?id=1001
GET /api/orders?page=1&page_size=20&status=paid
GET /api/node-reports?country=RU&node_id=1001
```

适合 GET 的参数：

```text
id
page
page_size
keyword
status
start_date
end_date
sort_by
sort_order
```

---

#### 3.2 查询参数中存在数组条件时，必须使用 POST

只要查询条件中存在数组，就必须使用 `POST`，不得使用 GET 拼接数组参数。

推荐：

```text
POST /api/users/search
POST /api/orders/search
POST /api/ad-accounts/search
POST /api/node-reports/search
```

请求体示例：

```json
{
  "page": 1,
  "page_size": 20,
  "status_list": ["active", "disabled"],
  "country_list": ["RU", "IR"],
  "created_start_date": "2026-05-01",
  "created_end_date": "2026-05-12"
}
```

不推荐：

```text
GET /api/users?status[]=active&status[]=disabled
GET /api/users?status_list=active,disabled
GET /api/users?ids=1,2,3
```

---

#### 3.3 复杂查询使用 POST

以下情况必须使用 `POST /api/{resources}/search`：

* 查询条件包含数组
* 查询条件包含对象
* 查询条件很多
* 查询条件存在嵌套结构
* 查询参数过长
* 查询条件涉及复杂报表
* 查询条件涉及多维度筛选
* 查询条件需要和前端筛选表单结构保持一致

推荐：

```text
POST /api/reports/search
POST /api/ad-reports/search
POST /api/node-reports/search
POST /api/dashboard/search
```

请求体示例：

```json
{
  "date_range": {
    "start_date": "2026-05-01",
    "end_date": "2026-05-12"
  },
  "filters": {
    "country_list": ["RU", "IR"],
    "app_id_list": [1001, 1002],
    "status_list": ["active"]
  },
  "metrics": ["revenue", "cost", "roi"],
  "dimensions": ["date", "country", "app_id"],
  "page": 1,
  "page_size": 20
}
```

---

### 4. 详情接口命名规则

详情接口可以使用 `GET`。

推荐：

```text
GET /api/users/detail?id=1001
GET /api/orders/detail?id=2001
GET /api/ad-accounts/detail?id=3001
```

如果项目更偏资源路径，也可以使用：

```text
GET /api/users/1001
GET /api/orders/2001
```

但同一项目内必须统一。

后台系统推荐统一使用：

```text
GET /api/{resources}/detail?id={id}
```

---

### 5. 新增接口命名规则

新增资源统一使用 `POST /api/{resources}/create`。

推荐：

```text
POST /api/users/create
POST /api/orders/create
POST /api/ad-accounts/create
POST /api/system-configs/create
```

请求体示例：

```json
{
  "name": "Oliver",
  "email": "oliver@example.com",
  "status": "active"
}
```

不推荐：

```text
POST /api/users
POST /api/add-user
POST /api/user/add
POST /api/createUser
```

---

### 6. 更新接口命名规则

更新某个资源的一个或多个字段时，统一使用：

```text
POST /api/{resources}/update
```

请求体中必须包含资源 ID。

推荐：

```text
POST /api/users/update
POST /api/orders/update
POST /api/ad-accounts/update
POST /api/system-configs/update
```

请求体示例：

```json
{
  "id": 1001,
  "name": "Oliver",
  "email": "oliver@example.com",
  "status": "active",
  "remark": "VIP user"
}
```

说明：

* 修改一个字段，使用 `POST /api/{resources}/update`
* 修改多个字段，也使用 `POST /api/{resources}/update`
* 不允许使用 `PUT /api/{resources}/{id}`
* 不允许使用 `PATCH /api/{resources}/{id}`
* 不建议为普通字段更新单独创建 `/change-name`、`/update-status`、`/modify-info` 等接口

不推荐：

```text
PUT   /api/users/1001
PATCH /api/users/1001
POST  /api/users/update-name
POST  /api/users/change-info
POST  /api/update-user
```

---

### 7. 删除接口命名规则

删除资源统一使用：

```text
POST /api/{resources}/delete
```

请求体中必须包含资源 ID。

推荐：

```text
POST /api/users/delete
POST /api/orders/delete
POST /api/ad-accounts/delete
```

请求体示例：

```json
{
  "id": 1001
}
```

批量删除使用：

```text
POST /api/{resources}/batch-delete
```

请求体示例：

```json
{
  "ids": [1001, 1002, 1003]
}
```

禁止使用：

```text
DELETE /api/users/1001
GET    /api/users/delete?id=1001
POST   /api/delete-user
```

---

### 8. 业务动作接口命名规则

当接口不是普通字段更新，而是一个明确业务动作时，可以使用动作命名。

推荐格式：

```text
POST /api/{resources}/{action}
```

请求体中传递 `id` 或其他业务参数。

示例：

```text
POST /api/users/freeze
POST /api/users/unfreeze
POST /api/users/enable
POST /api/users/disable
POST /api/orders/approve
POST /api/orders/reject
POST /api/orders/cancel
POST /api/tasks/retry
POST /api/ad-accounts/sync
```

请求体示例：

```json
{
  "id": 1001
}
```

动作名要求：

* 使用小写英文
* 多个单词使用短横线
* 使用明确动词
* 不使用模糊词

推荐动作：

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
refresh
reset
```

不推荐动作：

```text
do
action
handle
operate
change
submit
update-status
```

---

### 9. 状态切换接口命名规则

如果状态切换是明确业务动作，使用动作接口。

推荐：

```text
POST /api/users/enable
POST /api/users/disable
POST /api/users/freeze
POST /api/users/unfreeze
POST /api/orders/approve
POST /api/orders/reject
```

请求体：

```json
{
  "id": 1001
}
```

如果只是普通字段编辑的一部分，则使用：

```text
POST /api/users/update
```

请求体：

```json
{
  "id": 1001,
  "status": "disabled"
}
```

判断标准：

* 只是改字段：使用 `/update`
* 涉及状态流转、权限校验、日志、通知、事务、多表变更：使用明确动作接口

---

### 10. 批量操作接口命名规则

批量操作统一使用 `POST`。

推荐格式：

```text
POST /api/{resources}/batch-{action}
```

示例：

```text
POST /api/users/batch-delete
POST /api/users/batch-enable
POST /api/users/batch-disable
POST /api/ad-accounts/batch-sync
POST /api/orders/batch-approve
```

请求体：

```json
{
  "ids": [1, 2, 3]
}
```

批量更新多个字段时，推荐：

```text
POST /api/{resources}/batch-update
```

请求体：

```json
{
  "ids": [1, 2, 3],
  "data": {
    "status": "active",
    "group_id": 10
  }
}
```

不推荐：

```text
POST /api/deleteUsers
POST /api/users/delete-all
POST /api/users/multiDelete
```

---

### 11. 导入导出接口命名规则

导入导出统一优先使用 `POST`。

导出接口：

```text
POST /api/{resources}/export
```

示例：

```text
POST /api/users/export
POST /api/orders/export
POST /api/ad-reports/export
POST /api/node-reports/export
```

导出请求体可以包含复杂筛选条件：

```json
{
  "country_list": ["RU", "IR"],
  "status_list": ["active"],
  "start_date": "2026-05-01",
  "end_date": "2026-05-12"
}
```

导入接口：

```text
POST /api/{resources}/import
```

示例：

```text
POST /api/users/import
POST /api/ad-accounts/import
```

如果导出为异步任务，推荐：

```text
POST /api/export-tasks/create
GET  /api/export-tasks/detail?id=1001
POST /api/export-tasks/download
```

---

### 12. 嵌套资源命名规则

后台系统中不建议使用过深嵌套路由。

允许简单父子关系：

```text
GET  /api/projects/members?project_id=1001
POST /api/projects/members/create
POST /api/projects/members/delete
```

不推荐：

```text
GET /api/companies/{company_id}/projects/{project_id}/users/{user_id}/orders
```

层级过深时，应使用查询参数或请求体表达关系。

GET 简单查询：

```text
GET /api/orders?company_id=1&project_id=2&user_id=3
```

POST 复杂查询：

```text
POST /api/orders/search
```

请求体：

```json
{
  "company_id": 1,
  "project_id": 2,
  "user_id": 3,
  "status_list": ["paid", "pending"]
}
```

---

### 13. 参数命名规范

接口请求参数统一使用小写蛇形命名 `snake_case`。

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
status_list
country_list
```

不推荐：

```text
userId
pageSize
startDate
adAccountId
NodeID
statusList
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

### 14. 返回字段命名规范

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

---

### 15. 管理后台接口命名

如果项目同时存在用户端和管理端接口，应明确前缀。

管理后台推荐：

```text
/api/admin/users
/api/admin/orders
/api/admin/ad-accounts
```

用户端推荐：

```text
/api/user/profile
/api/user/orders
```

或者：

```text
/api/me
/api/me/orders
```

管理端创建、更新、删除示例：

```text
POST /api/admin/users/create
POST /api/admin/users/update
POST /api/admin/users/delete
POST /api/admin/users/freeze
```

---

### 16. API 版本号命名

如果项目需要 API 版本号，统一放在 `/api/v1` 后。

推荐：

```text
/api/v1/users
/api/v1/users/create
/api/v1/users/update
/api/v1/users/delete
/api/v1/users/search
```

不推荐：

```text
/v1/api/users
/api/users/v1
/api/user/v1/list
```

如果当前项目已有版本规范，必须沿用现有规范，不得擅自新增或变更版本前缀。

---

### 17. Laravel 路由命名规范

Laravel route name 使用点号分隔，按模块和动作命名。

推荐：

```php
Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::get('/users/detail', [UserController::class, 'show'])->name('users.show');
Route::post('/users/create', [UserController::class, 'store'])->name('users.store');
Route::post('/users/update', [UserController::class, 'update'])->name('users.update');
Route::post('/users/delete', [UserController::class, 'destroy'])->name('users.destroy');
Route::post('/users/freeze', [UserController::class, 'freeze'])->name('users.freeze');
```

复杂查询：

```php
Route::post('/users/search', [UserController::class, 'search'])->name('users.search');
```

管理端接口：

```php
Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::post('/users/create', [AdminUserController::class, 'store'])->name('users.store');
    Route::post('/users/update', [AdminUserController::class, 'update'])->name('users.update');
    Route::post('/users/delete', [AdminUserController::class, 'destroy'])->name('users.destroy');
});
```

---

### 18. Controller 方法命名规范

Controller 方法使用 Laravel 语义方法名和明确业务动作名。

推荐：

```text
index       列表
show        详情
search      复杂查询
store       创建
update      更新
destroy     删除
freeze      冻结
unfreeze    解冻
approve     审核通过
reject      审核拒绝
cancel      取消
retry       重试
sync        同步
export      导出
import      导入
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

### 19. 接口文档命名与接口命名关系

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

即：

* URL path：使用 `kebab-case`
* 请求参数：使用 `snake_case`
* 返回字段：使用 `snake_case`
* 文档文件：使用 `snake_case`
* Laravel route name：使用 `dot.case`
* HTTP Method：仅使用 `GET` / `POST`

---

### 20. Agent 执行要求

新增或修改接口时，Agent 必须检查：

1. 是否只使用 `GET` 或 `POST`
2. 是否错误使用了 `PUT`、`PATCH`、`DELETE`
3. 查询条件中存在数组时，是否使用了 `POST`
4. 接口路径是否符合资源和动作命名规范
5. 参数命名是否统一为 `snake_case`
6. 返回字段是否统一为 `snake_case`
7. Laravel Controller 方法名是否符合规范
8. Laravel route name 是否符合规范
9. 是否需要更新 `docs/api`
10. 是否需要更新 `version.md`

禁止 Agent 新增以下接口形式：

```text
PUT   /api/users/{id}
PATCH /api/users/{id}
DELETE /api/users/{id}
GET   /api/users?ids=1,2,3
GET   /api/users?status[]=active&status[]=disabled
/api/getXxx
/api/queryXxx
/api/xxx_list
/api/xxxAdd
/api/doSomething
/api/handle
```