增加**项目管理**后的设计。目标是：

```text
项目
  ├── 关联流量代理平台账号
  └── 关联广告变现平台账号
```

用于后续按项目统计：

```text
项目流量消耗
项目广告收入
项目 ROI / 成本收益
```

---

# 一、新增数据库表

## 1. 项目表

```sql
CREATE TABLE project_projects (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',

  project_code VARCHAR(100) NOT NULL UNIQUE COMMENT '项目代号，例如 app_abc、game_x',
  project_name VARCHAR(100) NOT NULL COMMENT '项目名称',

  owner_id BIGINT NULL COMMENT '所属人ID',
  owner_name VARCHAR(100) NULL COMMENT '所属人名称',

  department VARCHAR(100) NULL COMMENT '所属部门',
  status VARCHAR(30) NOT NULL DEFAULT 'active' COMMENT '状态：active、inactive、archived',

  remark VARCHAR(255) NULL COMMENT '备注',

  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',

  INDEX idx_owner_id (owner_id),
  INDEX idx_status (status)
) COMMENT='项目管理表';
```

---

## 2. 项目与流量平台账号关联表

用于把 KKOIP、Zenlayer 等流量代理账号关联到项目。

```sql
CREATE TABLE project_traffic_platform_accounts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',

  project_id BIGINT NOT NULL COMMENT '项目ID',
  project_code VARCHAR(100) NOT NULL COMMENT '项目代号',

  traffic_platform_account_id BIGINT NOT NULL COMMENT '流量平台账号ID，对应 traffic_platform_accounts.id',
  platform_code VARCHAR(50) NOT NULL COMMENT '流量平台编码，例如 kkoip',

  external_uid VARCHAR(100) NULL COMMENT '三方子账号ID，可选；为空表示整个账号归属项目',
  external_username VARCHAR(100) NULL COMMENT '三方子账号名称，可选',

  bind_type VARCHAR(30) NOT NULL DEFAULT 'account' COMMENT '绑定类型：account、sub_account',

  enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用',
  remark VARCHAR(255) NULL COMMENT '备注',

  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',

  UNIQUE KEY uk_project_traffic_account (
    project_id,
    traffic_platform_account_id,
    external_uid
  ),

  INDEX idx_project_id (project_id),
  INDEX idx_traffic_account_id (traffic_platform_account_id),
  INDEX idx_platform_code (platform_code)
) COMMENT='项目与流量平台账号关联表';
```

说明：

```text
external_uid 为空：
  整个 traffic_platform_account 归属于项目

external_uid 非空：
  只把该平台账号下的某个子账号归属于项目
```

---

## 3. 项目与广告变现账号关联表

如果广告变现已有账号表，例如：

```text
ad_platform_accounts
```

则新增关联表：

```sql
CREATE TABLE project_ad_platform_accounts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',

  project_id BIGINT NOT NULL COMMENT '项目ID',
  project_code VARCHAR(100) NOT NULL COMMENT '项目代号',

  ad_platform_account_id BIGINT NOT NULL COMMENT '广告变现平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '广告平台编码，例如 admob、applovin',

  external_app_id VARCHAR(100) NULL COMMENT '广告平台应用ID，可选',
  external_ad_unit_id VARCHAR(100) NULL COMMENT '广告位ID，可选',

  bind_type VARCHAR(30) NOT NULL DEFAULT 'account' COMMENT '绑定类型：account、app、ad_unit',

  enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用',
  remark VARCHAR(255) NULL COMMENT '备注',

  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',

  UNIQUE KEY uk_project_ad_account (
    project_id,
    ad_platform_account_id,
    external_app_id,
    external_ad_unit_id
  ),

  INDEX idx_project_id (project_id),
  INDEX idx_ad_account_id (ad_platform_account_id),
  INDEX idx_platform_code (platform_code)
) COMMENT='项目与广告变现账号关联表';
```

说明：

```text
bind_type = account
  整个广告账号归属项目

bind_type = app
  某个广告应用归属项目

bind_type = ad_unit
  某个广告位归属项目
```


# 三、PHP API 设计

统一前缀建议：

```text
/api/projects
```

---

# 四、项目管理 API

## 1. 项目列表

```http
GET /api/projects
```

参数：

| 参数        | 类型     | 必填 | 说明                           |
| --------- | ------ | -: | ---------------------------- |
| keyword   | string |  否 | 项目代号 / 项目名称                  |
| owner_id  | int    |  否 | 所属人 ID                       |
| status    | string |  否 | active / inactive / archived |
| page      | int    |  否 | 默认 1                         |
| page_size | int    |  否 | 默认 20                        |

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "list": [
      {
        "id": 1,
        "project_code": "game_001",
        "project_name": "Game Project 001",
        "owner_id": 1001,
        "owner_name": "张三",
        "department": "增长组",
        "status": "active",
        "remark": "测试项目",
        "created_at": "2026-04-28 10:00:00",
        "updated_at": "2026-04-28 10:00:00"
      }
    ],
    "total": 1
  }
}
```

---

## 2. 项目详情

```http
GET /api/projects/{id}
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "id": 1,
    "project_code": "game_001",
    "project_name": "Game Project 001",
    "owner_id": 1001,
    "owner_name": "张三",
    "department": "增长组",
    "status": "active",
    "remark": "测试项目"
  }
}
```

---

## 3. 新增项目

```http
POST /api/projects
```

参数：

```json
{
  "project_code": "game_001",
  "project_name": "Game Project 001",
  "owner_id": 1001,
  "owner_name": "张三",
  "department": "增长组",
  "status": "active",
  "remark": "测试项目"
}
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "id": 1
  }
}
```

---

## 4. 修改项目

```http
PUT /api/projects/{id}
```

参数：

```json
{
  "project_name": "Game Project 001",
  "owner_id": 1001,
  "owner_name": "张三",
  "department": "增长组",
  "status": "active",
  "remark": "更新备注"
}
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": true
}
```

---

## 5. 修改项目状态

```http
PATCH /api/projects/{id}/status
```

参数：

```json
{
  "status": "inactive"
}
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": true
}
```

---

# 五、项目关联流量账号 API

## 1. 查询项目已关联流量账号

```http
GET /api/projects/{id}/traffic-accounts
```

参数：

| 参数            | 类型     | 必填 | 说明               |
| ------------- | ------ | -: | ---------------- |
| platform_code | string |  否 | kkoip / zenlayer |
| enabled       | int    |  否 | 1 / 0            |

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": [
    {
      "id": 10,
      "project_id": 1,
      "project_code": "game_001",
      "traffic_platform_account_id": 3,
      "platform_code": "kkoip",
      "account_name": "kkoip-main",
      "external_uid": "1234567",
      "external_username": "kookeey",
      "bind_type": "sub_account",
      "enabled": 1,
      "remark": "项目代理子账号"
    }
  ]
}
```

---

## 2. 新增项目流量账号关联

```http
POST /api/projects/{id}/traffic-accounts
```

参数：

```json
{
  "traffic_platform_account_id": 3,
  "platform_code": "kkoip",
  "external_uid": "1234567",
  "external_username": "kookeey",
  "bind_type": "sub_account",
  "enabled": 1,
  "remark": "项目代理子账号"
}
```

如果绑定整个账号：

```json
{
  "traffic_platform_account_id": 3,
  "platform_code": "kkoip",
  "external_uid": "",
  "external_username": "",
  "bind_type": "account",
  "enabled": 1,
  "remark": "整个 KKOIP 账号归属项目"
}
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "id": 10
  }
}
```

---

## 3. 修改项目流量账号关联

```http
PUT /api/projects/{id}/traffic-accounts/{relation_id}
```

参数：

```json
{
  "external_uid": "1234567",
  "external_username": "kookeey",
  "bind_type": "sub_account",
  "enabled": 1,
  "remark": "更新备注"
}
```

---

## 4. 删除项目流量账号关联

```http
DELETE /api/projects/{id}/traffic-accounts/{relation_id}
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": true
}
```

---

# 六、项目关联广告账号 API

## 1. 查询项目已关联广告账号

```http
GET /api/projects/{id}/ad-accounts
```

参数：

| 参数            | 类型     | 必填 | 说明               |
| ------------- | ------ | -: | ---------------- |
| platform_code | string |  否 | admob / applovin |
| enabled       | int    |  否 | 1 / 0            |

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": [
    {
      "id": 20,
      "project_id": 1,
      "project_code": "game_001",
      "ad_platform_account_id": 5,
      "platform_code": "admob",
      "account_name": "admob-main",
      "external_app_id": "ca-app-pub-xxx~123",
      "external_ad_unit_id": "",
      "bind_type": "app",
      "enabled": 1,
      "remark": "项目 AdMob 应用"
    }
  ]
}
```

---

## 2. 新增项目广告账号关联

```http
POST /api/projects/{id}/ad-accounts
```

绑定整个广告账号：

```json
{
  "ad_platform_account_id": 5,
  "platform_code": "admob",
  "external_app_id": "",
  "external_ad_unit_id": "",
  "bind_type": "account",
  "enabled": 1,
  "remark": "整个广告账号归属项目"
}
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "id": 20
  }
}
```

---

## 3. 修改项目广告账号关联

```http
PUT /api/projects/{id}/ad-accounts/{relation_id}
```

参数：

```json
{
  "external_app_id": "ca-app-pub-xxx~123",
  "external_ad_unit_id": "ca-app-pub-xxx/456",
  "bind_type": "ad_unit",
  "enabled": 1,
  "remark": "更新备注"
}
```

---

## 4. 删除项目广告账号关联

```http
DELETE /api/projects/{id}/ad-accounts/{relation_id}
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": true
}
```

---

---

# 九、第一版最小接口清单

```text
GET    /api/projects
GET    /api/projects/{id}
POST   /api/projects
PUT    /api/projects/{id}
PATCH  /api/projects/{id}/status

GET    /api/projects/{id}/traffic-accounts
POST   /api/projects/{id}/traffic-accounts
PUT    /api/projects/{id}/traffic-accounts/{relation_id}
DELETE /api/projects/{id}/traffic-accounts/{relation_id}

GET    /api/projects/{id}/ad-accounts
POST   /api/projects/{id}/ad-accounts
PUT    /api/projects/{id}/ad-accounts/{relation_id}
DELETE /api/projects/{id}/ad-accounts/{relation_id}
```

核心设计原则：

```text
项目本身只做归属关系。
```
