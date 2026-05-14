### `docs/api`

当新增或修改接口时，除更新本文件外，还应检查是否需要更新对应接口文档。

---

#### 1. 已存在接口文档时

如果 `docs/api` 下已经存在对应模块文档，应优先更新已有文档，不要重复创建新文件。

示例：

```md
- 新增用户冻结接口，支持管理员冻结异常用户账号（app/Http/Controllers/UserStatusController.php）
````

同时应检查并更新：

```text
docs/api/user_api.md
```

---

#### 2. 接口文档不存在时的命名规范

如果本次新增或修改的接口在 `docs/api` 下没有对应文档，Agent 应按以下规则新建接口文档。

##### 命名原则

接口文档文件名必须按“业务模块”和后缀_api进行命名，而不是按单个接口命名。

推荐格式：

```text
docs/api/{module}_api.md
```

其中 `{module}` 使用小写英文，多个单词使用下划线 `_` 连接。

推荐示例：

```text
docs/api/user_api.md
docs/api/order_api.md
docs/api/node_report_api.md
docs/api/ad_account_api.md
docs/api/system_config_api.md
```

不推荐：

```text
docs/api/user_id.md
docs/api/get_user_list.md
docs/api/createUser.md
docs/api/user-api.md
docs/api/User.md
docs/api/api_user.md
```

---

#### 3. 模块命名规则

接口文档应按业务模块聚合。

例如：

用户相关接口统一放入：

```text
docs/api/user.md
```

包括：

```text
GET /api/users
GET /api/users/{id}
POST /api/users
PUT /api/users/{id}
DELETE /api/users/{id}
POST /api/users/{id}/freeze
```

订单相关接口统一放入：

```text
docs/api/order.md
```

包括：

```text
GET /api/orders
GET /api/orders/{id}
POST /api/orders
PUT /api/orders/{id}
POST /api/orders/{id}/cancel
```

广告账户相关接口统一放入：

```text
docs/api/ad_account.md
```

包括：

```text
GET /api/ad-accounts
GET /api/ad-accounts/{id}
POST /api/ad-accounts
PUT /api/ad-accounts/{id}
```

---

#### 4. 特殊命名规则

如果接口属于报表类、配置类、同步类、任务类，应按功能模块命名。

推荐：

```text
docs/api/dashboard.md
docs/api/report.md
docs/api/node_report.md
docs/api/user_report.md
docs/api/ad_report.md
docs/api/system_config.md
docs/api/data_sync.md
docs/api/task.md
docs/api/auth.md
docs/api/permission.md
```

不要按接口动作命名：

```text
docs/api/get_dashboard.md
docs/api/sync_data.md
docs/api/update_config.md
docs/api/check_permission.md
```

---

#### 5. 新建接口文档模板

新建 `docs/api/{module}.md` 时，应使用以下结构：

````md
# 模块名称接口文档

## 模块说明

说明该模块的业务用途、使用场景和主要调用方。

---

## 接口列表

| 接口名称 | 请求方法 | 请求路径 | 说明 |
|---|---|---|---|
| 用户列表 | GET | /api/users | 获取用户分页列表 |

---

## 接口详情

### 1. 接口名称

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/api/example`
- 权限要求：`auth:sanctum`
- 使用场景：说明前端在哪个页面或功能中使用

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---|---|---|
| page | integer | 否 | 1 | 当前页 |
| page_size | integer | 否 | 20 | 每页数量 |

#### 返回字段

| 字段名 | 类型 | 说明 |
|---|---|---|
| code | integer | 状态码 |
| message | string | 返回消息 |
| data | object | 返回数据 |

#### 返回示例

```json
{
  "code": 0,
  "message": "success",
  "data": {}
}
````

#### 错误码

| code | message      | 说明  |
| ---- | ------------ | --- |
| 401  | Unauthorized | 未登录 |
| 403  | Forbidden    | 无权限 |

#### 相关文件

* `routes/api.php`
* `app/Http/Controllers/ExampleController.php`
* `app/Http/Requests/ExampleRequest.php`
* `app/Services/ExampleService.php`

````

---

#### 6. Agent 执行要求

当接口文档不存在时，Agent 必须：

1. 判断接口所属业务模块
2. 按 `docs/api/{module}.md` 命名规则创建文档
3. 将同一业务模块的接口写入同一个文档
4. 不得为每个接口单独创建一个文档
5. 不得使用大小写混合、短横线、接口动作作为文件名
6. 创建或更新接口文档后，同步在 `version.md` 当前版本中记录

示例版本日志：

```md
- 新增用户接口文档，补充用户列表、详情和冻结接口说明（docs/api/user.md）
````

````
