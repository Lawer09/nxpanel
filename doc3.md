
## 一、核心思路

接口返回里只保留：

```text
groupName  -> project_code
date
country
impressions
clicks
spend
ctr
cpm
cpc
```

也就是说，第三方接口里的：

```json
"groupName": "A003"
```

对应你项目表里的：

```text
project_projects.project_code = A003
```

写库时转换成：

```text
project_code
```

---

# 二、新增表设计

## 1. 投放平台账号表

如果后续只接一个平台，也建议保留平台字段，方便扩展。

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

## 3. 未匹配项目记录表

建议保留，否则接口里出现新的 `groupName` 时数据会直接丢失。

```sql
CREATE TABLE ad_spend_platform_unmatched_reports (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',

  platform_account_id BIGINT NOT NULL COMMENT '投放平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '投放平台编码',

  raw_group_name VARCHAR(100) NOT NULL COMMENT '接口返回 groupName',
  report_date DATE NOT NULL COMMENT '报表日期',
  country VARCHAR(50) NOT NULL DEFAULT '' COMMENT '国家或地区',

  impressions BIGINT NOT NULL DEFAULT 0 COMMENT '展示数',
  clicks BIGINT NOT NULL DEFAULT 0 COMMENT '点击数',
  spend DECIMAL(20, 6) NOT NULL DEFAULT 0 COMMENT '消耗金额',

  ctr DECIMAL(12, 6) NULL COMMENT '点击率',
  cpm DECIMAL(20, 6) NULL COMMENT '千次展示成本',
  cpc DECIMAL(20, 6) NULL COMMENT '点击成本',

  raw_data JSON NULL COMMENT '原始数据',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',

  UNIQUE KEY uk_unmatched_daily (
    platform_account_id,
    raw_group_name,
    report_date,
    country
  ),

  INDEX idx_raw_group_name (raw_group_name),
  INDEX idx_report_date (report_date)
) COMMENT='投放平台未匹配项目日报表';
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

# 三、数据写入逻辑

## 1. 登录获取 token

```http
POST http://console.adsmakeup.com/api/auth/login
```

请求：

```json
{
  "username": "beilin",
  "password": "Admin@123"
}
```

拿到 token 后请求报表：

```http
GET /api/ads/report/day/overall
Authorization: Bearer {token}
```

---

## 2. 固定查询参数

```text
objectName=account
dims=date
dims=group_id
dims=country
startDate=2026-04-28
endDate=2026-04-28
current=1
size=20
```

注意 `dims` 有多个，URL 里应重复传：

```text
dims=date&dims=group_id&dims=country
```

---

## 3. 分页拉取

接口返回：

```json
"current": "1",
"size": "20",
"pages": "1",
"total": "3"
```

同步逻辑：

```text
current = 1
while current <= pages:
  请求当前页
  处理 records
  current++
```

建议 `size` 配置成：

```text
size=200
```

---

## 4. groupName 转 project_id

处理每条记录：

```text
groupName = record.groupName
↓
SELECT id FROM project_projects WHERE project_code = groupName
```

如果匹配到：

```text
写入 ad_spend_platform_daily_reports
```

如果未匹配：

```text
写入 ad_spend_platform_unmatched_reports
```

---

## 5. Upsert 规则

匹配成功时，唯一键：

```text
platform_account_id + project_code + report_date + country
```

重复同步时更新：

```text
impressions
clicks
spend
ctr
cpm
cpc
updated_at
```

---

# 四、字段处理规则

## groupName

```text
接口 groupName -> project_code
```

---

## date

```text
接口 date -> report_date
```

---

## country

接口可能为 `null`，建议统一存：

```text
''
```

不要存 NULL，方便唯一键和查询。

---

## impressions / clicks

接口是字符串：

```json
"impressions": "11425",
"clicks": "1013"
```

入库转成 BIGINT。

---

## spend / ctr / cpm / cpc

接口可能为数字，也可能为 null。

```text
spend null -> 0
ctr/cpm/cpc null -> NULL
```

---

# 五、同步范围建议

定时任务可以这样：

```text
每 30 分钟：
  同步今天

每 2 小时：
  回补昨天
```

原因：

```text
投放平台数据通常有延迟或回刷。
```

---

# 六、PHP 查询 API 建议

## 1. 投放日报明细

```http
GET /api/ad-spend-platform/reports/daily
```

参数：

```text
platform_code
account_id
project_code
start_date
end_date
country
page
page_size
```

返回：

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "list": [
      {
        "report_date": "2026-04-28",
        "project_id": 1,
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

## 4. 未匹配项目列表

```http
GET /api/ad-spend-platform/unmatched
```

用于前端发现 groupName 没有对应项目。

---