下面是一版适合 **PHP Laravel 项目** 使用的根目录 `version.md`，可以直接复制到项目根目录。

````md
# Version Log

## Agent 维护说明（必读）

当前开发版本：`1.0.0`

请后续 Agent 严格按以下规则维护此文件。

---

## 1. 当前版本规则

- 以顶部 `当前开发版本：x.y.z` 作为唯一准则。
- 只允许在当前开发版本对应的 `## [x.y.z]` 区块下追加内容。
- 禁止修改历史版本内容。
- 历史版本一旦切换后，视为冻结，只读，不再新增、删除或改写条目。
- 如果当前开发版本对应区块不存在，必须在文件末尾新增该版本区块。

---

## 2. 记录粒度

- 一次功能改动对应一条日志。
- 日志必须具体说明：做了什么 + 影响范围。
- 如涉及关键文件，建议在末尾补充文件路径。
- 避免空泛描述。

不推荐：

```md
- 优化代码
- 修复问题
- 调整接口
````

推荐：

```md
- 新增用户列表分页接口，支持按状态和注册时间筛选（app/Http/Controllers/UserController.php）
- 优化订单查询逻辑，将复杂筛选条件下沉到 OrderRepository（app/Repositories/OrderRepository.php）
- 修复用户状态更新缺少事务保护的问题，避免多表状态不一致（app/Services/UserService.php）
```

---

## 3. 分类标准

每个版本下按以下分类记录。

### 新增功能

用于记录全新能力、页面、接口、命令、任务、队列、模块、交互等。

示例：

```md
- 新增用户导出接口，支持按注册时间和状态筛选后导出 Excel（app/Http/Controllers/UserExportController.php）
```

### 优化功能

用于记录已有功能的性能、体验、结构、可维护性、查询效率、代码组织方式等改进。

示例：

```md
- 优化用户列表查询结构，将筛选条件统一封装到 UserRepository，减少 Controller 查询逻辑（app/Repositories/UserRepository.php）
```

### Bug 修复

用于记录明确的问题修复，包括异常、边界问题、错误逻辑、兼容性问题、权限问题、数据不一致问题等。

示例：

```md
- 修复订单状态更新失败时未回滚库存的问题，增加事务保护避免订单和库存数据不一致（app/Services/OrderService.php）
```

---

## 4. Laravel 项目记录要求

Laravel 项目每次记录时，应尽量说明影响的层级。

常见影响范围包括：

* `routes/api.php`
* `routes/web.php`
* `app/Http/Controllers`
* `app/Http/Requests`
* `app/Http/Resources`
* `app/Services`
* `app/Repositories`
* `app/Models`
* `app/Jobs`
* `app/Console/Commands`
* `app/Mail`
* `app/Notifications`
* `app/Policies`
* `app/Middleware`
* `database/migrations`
* `database/seeders`
* `database/factories`
* `config`
* `tests`

记录时推荐写清楚：

```text
动作 + 具体模块/接口/功能 + 业务影响范围 + 文件路径
```

示例：

```md
- 新增用户创建参数校验，统一校验手机号、邮箱和状态字段，避免无效数据进入 Service 层（app/Http/Requests/UserStoreRequest.php）
- 优化用户详情返回结构，隐藏密码、remember_token 等敏感字段（app/Http/Resources/UserResource.php）
- 修复 API 路由缺少 auth:sanctum 中间件导致未登录可访问的问题（routes/api.php）
```

---

## 5. 结构规则

* 版本标题格式固定：

```md
## [版本号] - YYYY-MM-DD
```

* 条目统一使用 `- ` 无序列表。
* 分类标题固定为：

```md
### 新增功能

### 优化功能

### Bug 修复
```

* 某分类若无内容，可以暂不填写。
* 不要写 `无`、`暂无`、`N/A`。
* 新增记录只能追加到对应分类下，不要重排历史记录。
* 不要删除空分类标题，除非项目另有统一规范。

---

## 6. 发版切换规则

* 只通过修改顶部 `当前开发版本：x.y.z` 来切换开发版本。
* 切换版本后，上一版本区块自动冻结。
* 如果新版本区块不存在，则在文件末尾新增版本模板：

```md
## [x.y.z] - YYYY-MM-DD

### 新增功能

### 优化功能

### Bug 修复
```

* 后续所有改动记录，只能写入当前开发版本对应区块。
* 禁止为了补充遗漏而修改已冻结版本。
* 如确需补充历史说明，应在当前版本中追加一条说明，而不是修改历史版本。

---

## 7. 新版本描述建议

推荐句式：

```text
动词 + 具体功能/模块 + 场景/影响范围（可选文件路径）
```

常用动词：

* 新增
* 支持
* 接入
* 优化
* 调整
* 拆分
* 统一
* 修复
* 补充
* 移除

示例：

```md
- 新增用户列表接口，支持按状态、注册时间和关键词分页查询（app/Http/Controllers/UserController.php）
- 接入 Sanctum 登录认证，统一 API 登录态校验逻辑（routes/api.php）
- 优化订单创建流程，增加数据库事务保护多表写入一致性（app/Services/OrderService.php）
- 拆分用户查询逻辑，将复杂筛选条件迁移到 UserRepository（app/Repositories/UserRepository.php）
- 修复用户详情接口返回 remember_token 的问题，避免敏感字段泄露（app/Http/Resources/UserResource.php）
```

---

## 8. Agent 执行要求

每次 Agent 修改代码后，必须检查本文件：

1. 读取顶部 `当前开发版本`
2. 查找对应版本区块
3. 根据本次改动内容，追加到正确分类
4. 如果对应版本区块不存在，先创建版本区块
5. 不得修改非当前版本的任何内容
6. 不得改写历史版本日志
7. 不得写空泛描述
8. 不得编造未实际完成的修改内容

---

## 9. 与其他文档的关系

如果项目中存在以下目录，Agent 应根据实际改动同步检查：

```text
docs/
├── api/
├── issue/
└── components/
```

### `docs/api`

当新增或修改接口时，除更新本文件外，还应检查是否需要更新对应接口文档。

示例：

```md
- 新增用户冻结接口，支持管理员冻结异常用户账号（app/Http/Controllers/UserStatusController.php）
```

同时应检查：

```text
docs/api/user_id.md
```

### `docs/issue`

当修复通用问题、兼容性问题、过时方法、容易重复踩坑的问题时，应检查是否需要补充 issue 记录。

示例：

```md
- 修复 Laravel 11 中异常处理配置位置变化导致自定义 API 错误格式未生效的问题（bootstrap/app.php）
```

同时可记录到：

```text
docs/issue/global.md
```

### `docs/components`

如果项目包含 Blade 组件、前端组件或可复用业务组件，新增或修改全局组件时，应检查是否需要更新组件说明。

---

## 10. 禁止事项

Agent 维护本文件时禁止：

* 禁止修改历史版本内容
* 禁止删除历史版本内容
* 禁止重排历史版本顺序
* 禁止把当前版本日志写入错误版本区块
* 禁止不看顶部当前版本就追加日志
* 禁止写空泛日志
* 禁止编造未完成的功能
* 禁止把测试、构建失败写成已通过
* 禁止把业务无关改动写入版本日志
* 禁止将敏感信息、token、密钥、账号密码写入版本日志

---

## 11. 当前版本日志

## [1.0.0] - 2026-05-12

### 新增功能

### 优化功能

- 重构项目 CRUD 接口：Controller 拆出业务逻辑到 ProjectService，新增 FormRequest 校验层（ProjectFetchRequest/ProjectSaveRequest/ProjectUpdateRequest/ProjectUpdateStatusRequest），新增 ProjectResource 统一格式化返回字段（app/Http/Controllers/V3/Admin/ProjectController.php, app/Services/ProjectService.php, app/Http/Requests/Admin/Project*.php, app/Http/Resources/ProjectResource.php）
- 新增项目管理接口文档，包含列表、详情、新增、修改、状态变更共 5 个接口的完整说明（docs/api/project.md）
- 新增 Firebase Analytics 模块接口文档，覆盖 Dashboard、VPN、测速、API 错误、明细与筛选项接口（docs/api/firebase_analytics.md）
- 补充 Firebase Analytics 接口统计口径/数据来源说明，并标注缓存建议（docs/api/firebase_analytics.md）
- 补充 Firebase 事件表 migration，包含表与索引创建，并在存在时跳过（database/migrations/2026_05_14_120000_create_firebase_event_tables.php）

### Bug 修复

```
```
