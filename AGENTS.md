# AGENTS.md

## 项目说明

当前项目为 **PHP Laravel 项目**（Laravel 12，PHP ^8.2），用于后端 API、管理后台、任务调度与报表聚合等业务。

所有 Agent 在修改代码前，必须先阅读并遵守本规范。

---

## 一、Agent 基本工作原则

### 1. 修改前先理解项目结构

至少先查看以下目录和文件：

- `composer.json`
- `package.json`（若存在）
- `app/`
- `routes/`
- `app/Http/Routes/`（本项目 API 路由主目录）
- `config/`
- `database/migrations/`
- `database/seeders/`
- `database/factories/`
- `resources/`
- `tests/`（当前仓库不存在，新增测试时需补齐）
- `.env.example`
- `README.md`（若存在）
- `docs/`
- `version.md`

禁止在未理解现有结构和业务逻辑时直接重构。

### 2. 优先复用现有实现

新增功能优先复用已有：

- Controller
- Service
- Model
- Form Request
- Resource
- Middleware
- Helper / Utils
- 异常处理与响应格式

无明确收益时，禁止重复造轮子。

### 3. 控制改动范围

每次变更应聚焦当前需求，避免无关修改。

禁止：

- 无需求大规模重构
- 无理由调整目录结构
- 无理由修改全局配置
- 无理由修改认证、权限、中间件链路
- 无理由修改历史接口返回结构
- 无理由引入大型新依赖

---

## 二、Laravel 分层开发规范

### 1) Controller 规范

Controller 仅负责：

- 接收请求
- 调用 Form Request 校验参数
- 调用 Service 执行业务
- 返回统一响应

禁止在 Controller 中堆积复杂业务流程、复杂 SQL、外部系统编排。

### 2) Service 规范

Service 承载核心业务逻辑，包含：

- 业务流程编排
- 多模型协作
- 事务处理
- 状态流转
- 聚合计算
- 外部服务调用编排

涉及事务必须显式使用事务，保证幂等与一致性。

### 3) Repository 规范

本项目当前未形成统一 Repository 层，默认不强制新增。

以下场景可新增 Repository：

- 查询逻辑复杂且跨多个 Service 复用
- 需要统一封装复杂数据访问

Repository 只负责数据访问，不承载业务规则。

### 4) Model 规范

Model 负责：

- 表结构映射
- casts / fillable / hidden
- 关联关系
- scope

禁止在 Model 中编排重业务流程。

### 5) Form Request 规范

涉及新增、编辑、筛选查询的接口，优先使用 Form Request。

规则应明确：

- 类型
- 必填
- 取值范围
- 枚举
- 长度

禁止在 Controller 手写大量重复校验。

### 6) Resource 规范

API 返回优先通过 Resource 统一格式化，保持字段风格一致（遵循当前接口约定）。

禁止直接返回未过滤敏感字段的模型数据。

### 7) Middleware 规范

横切能力放中间件：

- 认证与权限
- 语言
- JSON 强制
- 请求日志/耗时
- 签名或来源校验

中间件职责单一，禁止塞入业务流程。

---

## 三、路由、权限、异常处理规范

### 1) API 路由规范

本项目 API 路由按版本放在：

- `app/Http/Routes/V1/*.php`
- `app/Http/Routes/V2/*.php`
- `app/Http/Routes/V3/*.php`

由 `RouteServiceProvider` 动态加载并挂载到 `/api/v1|v2|v3`。

新增 API 必须：
- 参照 `doc/api_define.md` 中的定义
- 明确请求方法、URI、Controller 方法
- 明确使用的 Request 校验类
- 明确中间件与权限边界
- 明确返回字段与文档同步

### 2) Web 路由规范

Web 页面路由放在 `routes/web.php`。

API 逻辑不得混入 Web 路由（除非已有明确兼容约束且有说明）。

### 3) 权限与中间件规范

优先复用现有中间件别名（见 `app/Http/Kernel.php`）：

- `admin`
- `user`
- `client`
- `staff`
- `auth`
- `abilities` / `ability`
- `duration` / `log`

管理端与敏感接口必须显式挂载鉴权与权限中间件。

### 4) 异常处理规范

统一走 `app/Exceptions/Handler.php`。

- 业务异常使用明确异常类型（如 `ApiException` / `BusinessException`）
- 参数错误遵循 ValidationException 统一格式
- 禁止向前端暴露敏感堆栈信息（生产环境）

---

## 四、数据库规范（Migration / Seeder / Factory）

### 1) Migration 规范

- 所有结构变更必须通过 migration 提交
- 命名需准确表达变更目的
- 评估索引与锁表影响
- 对已发布 migration 禁止直接改历史，使用新增 migration 修正

### 2) Seeder 规范

- Seeder 仅用于初始化或可控数据补充
- 尽量幂等，可重复执行
- 避免在 Seeder 写不可控破坏性逻辑

### 3) Factory 规范

- Factory 用于测试和本地构造数据
- 默认 state 必须可用、可读

---

## 五、文档规范（docs 目录）

`docs/` 为项目文档主目录，至少遵循：

- `docs/api/`：接口文档（具体参考docs/api.md）
- `docs/issues/`：问题记录（现象、原因、修复、回归）（如不存在可新增）
- `docs/components/`：组件/模块说明（职责、边界、依赖）（如不存在可新增）
- 其他专题文档按主题命名，避免无语义文件名

新增或修改接口、命令、报表口径时，必须同步更新对应文档。

---

## 六、响应与接口规范

本项目已有 `ApiResponse` trait，新增接口应保持现有统一响应习惯，避免同项目多套无规则结构并存。

分页/列表接口至少明确：

- 数据列表
- total
- page
- pageSize

字段命名、单位、默认值必须与实现一致，并在文档中说明。

---

## 七、版本与变更记录规范（强制）

1. 每次代码修改后，**必须更新根目录 `version.md`**。
2. 若 `version.md` 不存在，先创建再更新。
3. 版本日志只允许追加，**禁止修改历史版本日志**。
4. 每条记录应包含：日期、变更摘要、影响范围、是否需要迁移/回滚说明。

---

## 八、安全规范（强制）

禁止将以下信息写入代码、文档、日志、测试样例：

- `.env` 实际内容
- token / access key / secret key
- 数据库密码
- 私钥/证书原文
- 第三方平台敏感凭据

配置项统一走 `.env` + `config/*`，示例值仅可使用占位符。

---

## 九、提交前自检清单

- 是否仅修改需求相关文件
- 是否复用现有分层和中间件
- 是否补充/更新 migration（如涉及数据库）
- 是否同步更新 `docs/`（如涉及接口/口径/命令）
- 是否更新 `version.md`
- 是否检查无敏感信息泄露

---

## 十、PR 必填检查项

提交 PR 前必须逐项确认：

### 代码质量
- [ ] 无残留调试代码（`dd`、`dump`、`var_dump`、`logger->debug`、`print_r` 等）
- [ ] 无注释掉的旧代码（除非有明确理由并加注释说明）
- [ ] 无无关空白/格式化改动混入（非目标文件的格式变更单独提 PR）
- [ ] 新代码已考虑边界条件和异常路径

### 兼容性
- [ ] 改动不破坏现有 API 返回结构（字段名、类型、单位）（却非明确不考虑兼容）
- [ ] 改动不破坏现有前端/客户端依赖的接口（却非明确不考虑兼容）
- [ ] 若删除了某个接口/命令/配置项，确认没有其他模块依赖它
- [ ] 若新增配置项，同步更新了 `.env.example` 和对应 `config/*` 文档

### 数据与数据库
- [ ] 迁移文件同时包含 `up` 和 `down` 方法（回滚路径明确）
- [ ] 迁移文件已评估锁表风险（尤其是大表加索引/改列类型）
- [ ] 若涉及数据迁移（`DB::statement` / raw SQL），已确认幂等、可回滚
- [ ] 敏感字段（密码、token、密钥）未出现在迁移、seeder、factory 示例数据中

### 文档与版本
- [ ] `version.md` 已更新（追加，不修改历史）
- [ ] 新增/修改的接口、命令、口径在 `docs/` 中有对应文档
- [ ] 文档中的示例请求/返回值与实现一致

### 依赖
- [ ] 无未使用的新增依赖
- [ ] 新增 composer/npm 包有明确不可替代的理由

---
