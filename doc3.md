
## 一、核心思路

# 二、新增表设计

## 1. 投放平台账号表

```sql
CREATE TABLE ad_spend_platform_accounts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',

  platform_code VARCHAR(50) NOT NULL COMMENT '投放平台编码，例如 adsmakeup',
  account_name VARCHAR(100) NOT NULL COMMENT '账号名称',

  base_url VARCHAR(255) NOT NULL COMMENT '接口基础地址，例如 http://console.adsmakeup.com',
  username VARCHAR(100) NOT NULL COMMENT '登录用户名',
  password VARCHAR(255) NOT NULL COMMENT '登录密码，建议加密存储',

  access_token TEXT NULL COMMENT '登录后获取的 token，可缓存',
  token_expired_at DATETIME NULL COMMENT 'token 过期时间，如平台未返回可为空',

  enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用',
  remark VARCHAR(255) NULL COMMENT '备注',

  last_sync_at DATETIME NULL COMMENT '最近同步时间',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',

  INDEX idx_platform_enabled (platform_code, enabled)
) COMMENT='投放平台账号配置表';
```

---

## 2. 投放消耗日报表

这里只存你需要的字段。

```sql
CREATE TABLE ad_spend_platform_daily_reports (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',

  platform_account_id BIGINT NOT NULL COMMENT '投放平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '投放平台编码，例如 adsmakeup',

  project_code VARCHAR(100) NOT NULL COMMENT '项目代号，即接口返回 groupName',

  report_date DATE NOT NULL COMMENT '报表日期',
  country VARCHAR(50) NOT NULL DEFAULT '' COMMENT '国家或地区，接口 country 为空时存空字符串',

  impressions BIGINT NOT NULL DEFAULT 0 COMMENT '展示数',
  clicks BIGINT NOT NULL DEFAULT 0 COMMENT '点击数',
  spend DECIMAL(20, 6) NOT NULL DEFAULT 0 COMMENT '消耗金额',

  ctr DECIMAL(12, 6) NULL COMMENT '点击率',
  cpm DECIMAL(20, 6) NULL COMMENT '千次展示成本',
  cpc DECIMAL(20, 6) NULL COMMENT '点击成本',

  raw_group_name VARCHAR(100) NOT NULL COMMENT '接口原始 groupName',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',

  UNIQUE KEY uk_ad_spend_daily (
    platform_account_id,
    project_id,
    report_date,
    country
  ),

  INDEX idx_project_date (project_id, report_date),
  INDEX idx_platform_date (platform_code, report_date),
  INDEX idx_report_date (report_date)
) COMMENT='投放平台项目日报消耗表';
```
---

## 4. 同步任务表

```sql
CREATE TABLE ad_spend_platform_sync_jobs (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',

  platform_account_id BIGINT NOT NULL COMMENT '投放平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '投放平台编码',

  start_date DATE NOT NULL COMMENT '同步开始日期',
  end_date DATE NOT NULL COMMENT '同步结束日期',

  status VARCHAR(20) NOT NULL COMMENT 'running、success、failed',

  total_records INT NOT NULL DEFAULT 0 COMMENT '接口返回总记录数',
  matched_records INT NOT NULL DEFAULT 0 COMMENT '成功匹配项目记录数',
  unmatched_records INT NOT NULL DEFAULT 0 COMMENT '未匹配项目记录数',

  request_params JSON NULL COMMENT '请求参数',
  error_message TEXT NULL COMMENT '错误信息',

  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',

  INDEX idx_account_status (platform_account_id, status),
  INDEX idx_date_range (start_date, end_date)
) COMMENT='投放平台同步任务记录表';
```

---

# 六、PHP 查询 API 建议

统一前缀：

```text
/api/ad-spend-platform
```

统一返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {}
}
```

---

# 1. 投放平台账号配置

## 1.1 账号列表

```http
GET /api/ad-spend-platform/accounts
```

参数：

| 参数            | 类型     | 必填 | 说明           |
| ------------- | ------ | -: | ------------ |
| platform_code | string |  否 | 默认 adsmakeup |
| enabled       | int    |  否 | 1 启用，0 禁用    |
| keyword       | string |  否 | 账号名称 / 用户名   |
| page          | int    |  否 | 默认 1         |
| page_size     | int    |  否 | 默认 20        |

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "list": [
      {
        "id": 1,
        "platform_code": "adsmakeup",
        "account_name": "AdsMakeup 主账号",
        "base_url": "http://console.adsmakeup.com",
        "username": "beilin",
        "enabled": 1,
        "last_sync_at": "2026-04-28 10:00:00",
        "remark": "主投放账号",
        "created_at": "2026-04-28 10:00:00",
        "updated_at": "2026-04-28 10:00:00"
      }
    ],
    "total": 1
  }
}
```

---

## 1.2 账号详情

```http
GET /api/ad-spend-platform/accounts/{id}
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "id": 1,
    "platform_code": "adsmakeup",
    "account_name": "AdsMakeup 主账号",
    "base_url": "http://console.adsmakeup.com",
    "username": "beilin",
    "password_masked": "******",
    "enabled": 1,
    "last_sync_at": "2026-04-28 10:00:00",
    "remark": "主投放账号"
  }
}
```

---

## 1.3 新增账号

```http
POST /api/ad-spend-platform/accounts
```

参数：

```json
{
  "platform_code": "adsmakeup",
  "account_name": "AdsMakeup 主账号",
  "base_url": "http://console.adsmakeup.com",
  "username": "beilin",
  "password": "Admin@123",
  "enabled": 1,
  "remark": "主投放账号"
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

## 1.4 修改账号

```http
PUT /api/ad-spend-platform/accounts/{id}
```

参数：

```json
{
  "account_name": "AdsMakeup 主账号",
  "base_url": "http://console.adsmakeup.com",
  "username": "beilin",
  "password": "",
  "enabled": 1,
  "remark": "更新备注"
}
```

说明：

```text
password 为空表示不修改原密码
password 非空表示更新密码，并清空旧 token
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

## 1.5 启用 / 禁用账号

```http
PATCH /api/ad-spend-platform/accounts/{id}/status
```

参数：

```json
{
  "enabled": 0
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

## 1.6 测试账号登录

```http
POST /api/ad-spend-platform/accounts/{id}/test
```

参数：无

返回：

```json
{
  "code": 0,
  "msg": "测试成功",
  "data": {
    "login_success": true
  }
}
```

---

# 2. 投放数据同步

## 2.1 手动同步

```http
POST /api/ad-spend-platform/sync
```

参数：

```json
{
  "account_id": 1,
  "start_date": "2026-04-28",
  "end_date": "2026-04-28"
}
```

返回：

```json
{
  "code": 0,
  "msg": "同步任务已提交",
  "data": {
    "job_id": 1001
  }
}
```

---

## 2.2 同步任务列表

```http
GET /api/ad-spend-platform/sync-jobs
```

参数：

| 参数            | 类型     | 必填 | 说明                         |
| ------------- | ------ | -: | -------------------------- |
| account_id    | int    |  否 | 投放平台账号 ID                  |
| platform_code | string |  否 | adsmakeup                  |
| status        | string |  否 | running / success / failed |
| start_date    | string |  否 | 同步数据开始日期                   |
| end_date      | string |  否 | 同步数据结束日期                   |
| page          | int    |  否 | 默认 1                       |
| page_size     | int    |  否 | 默认 20                      |

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "list": [
      {
        "id": 1001,
        "platform_account_id": 1,
        "platform_code": "adsmakeup",
        "account_name": "AdsMakeup 主账号",
        "start_date": "2026-04-28",
        "end_date": "2026-04-28",
        "status": "success",
        "total_records": 3,
        "success_records": 3,
        "error_message": "",
        "created_at": "2026-04-28 10:00:00",
        "updated_at": "2026-04-28 10:00:03"
      }
    ],
    "total": 1
  }
}
```

---

## 2.3 同步任务详情

```http
GET /api/ad-spend-platform/sync-jobs/{id}
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "id": 1001,
    "platform_account_id": 1,
    "platform_code": "adsmakeup",
    "account_name": "AdsMakeup 主账号",
    "start_date": "2026-04-28",
    "end_date": "2026-04-28",
    "status": "success",
    "total_records": 3,
    "success_records": 3,
    "request_params": {
      "objectName": "account",
      "dims": ["date", "group_id", "country"],
      "startDate": "2026-04-28",
      "endDate": "2026-04-28",
      "size": 200
    },
    "error_message": "",
    "created_at": "2026-04-28 10:00:00",
    "updated_at": "2026-04-28 10:00:03"
  }
}
```

---

# 3. 投放日报查询

## 3.1 日报明细

```http
GET /api/ad-spend-platform/reports/daily
```

参数：

| 参数            | 类型     | 必填 | 说明               |
| ------------- | ------ | -: | ---------------- |
| platform_code | string |  否 | adsmakeup        |
| account_id    | int    |  否 | 投放账号 ID          |
| project_code  | string |  否 | 项目代号，即 groupName |
| country       | string |  否 | 国家，空字符串表示无国家     |
| start_date    | string |  是 | 开始日期             |
| end_date      | string |  是 | 结束日期             |
| page          | int    |  否 | 默认 1             |
| page_size     | int    |  否 | 默认 50            |

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "list": [
      {
        "id": 1,
        "report_date": "2026-04-28",
        "platform_account_id": 1,
        "platform_code": "adsmakeup",
        "account_name": "AdsMakeup 主账号",
        "project_code": "A003",
        "country": "",
        "impressions": 11425,
        "clicks": 1013,
        "spend": "344.340000",
        "ctr": "8.866500",
        "cpm": "30.139200",
        "cpc": "0.339900",
        "updated_at": "2026-04-28 10:00:03"
      }
    ],
    "total": 1
  }
}
```

---

## 3.2 投放汇总

```http
GET /api/ad-spend-platform/reports/summary
```

参数：

| 参数            | 类型     | 必填 | 说明                                            |
| ------------- | ------ | -: | --------------------------------------------- |
| platform_code | string |  否 | adsmakeup                                     |
| account_id    | int    |  否 | 投放账号 ID                                       |
| project_code  | string |  否 | 项目代号                                          |
| country       | string |  否 | 国家                                            |
| start_date    | string |  是 | 开始日期                                          |
| end_date      | string |  是 | 结束日期                                          |
| group_by      | string |  否 | project / account / country / date，默认 project |

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": [
    {
      "project_code": "A003",
      "impressions": 11425,
      "clicks": 1013,
      "spend": "344.340000",
      "ctr": "8.866500",
      "cpm": "30.139200",
      "cpc": "0.339900"
    }
  ]
}
```

---

## 3.4 项目代号列表

用于前端筛选。

```http
GET /api/ad-spend-platform/project-codes
```

参数：

| 参数         | 类型     | 必填 | 说明     |
| ---------- | ------ | -: | ------ |
| keyword    | string |  否 | 项目代号搜索 |
| start_date | string |  否 | 限定日期范围 |
| end_date   | string |  否 | 限定日期范围 |

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": [
    {
      "project_code": "A003"
    },
    {
      "project_code": "A002"
    }
  ]
}
```

---

# 4. 项目页投放接口

统一使用：

```text
project_code
```

---


---

## 4.3 项目投放日报明细

```http
GET /api/projects/{project_code}/ad-spend-daily
```

参数：

| 参数         | 类型     | 必填 | 说明      |
| ---------- | ------ | -: | ------- |
| start_date | string |  是 | 开始日期    |
| end_date   | string |  是 | 结束日期    |
| country    | string |  否 | 国家      |
| account_id | int    |  否 | 投放账号 ID |
| page       | int    |  否 | 默认 1    |
| page_size  | int    |  否 | 默认 50   |

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "list": [
      {
        "report_date": "2026-04-28",
        "platform_account_id": 1,
        "platform_code": "adsmakeup",
        "account_name": "AdsMakeup 主账号",
        "project_code": "A003",
        "country": "",
        "impressions": 11425,
        "clicks": 1013,
        "spend": "344.340000",
        "ctr": "8.866500",
        "cpm": "30.139200",
        "cpc": "0.339900"
      }
    ],
    "total": 1
  }
}
```

---

# 5. 前端页面对应接口

## 投放账号配置页

```text
GET    /api/ad-spend-platform/accounts
GET    /api/ad-spend-platform/accounts/{id}
POST   /api/ad-spend-platform/accounts
PUT    /api/ad-spend-platform/accounts/{id}
PATCH  /api/ad-spend-platform/accounts/{id}/status
POST   /api/ad-spend-platform/accounts/{id}/test
```

## 手动同步 / 同步任务页

```text
POST   /api/ad-spend-platform/sync
GET    /api/ad-spend-platform/sync-jobs
GET    /api/ad-spend-platform/sync-jobs/{id}
```

## 投放日报页面

```text
GET /api/ad-spend-platform/reports/daily
```
