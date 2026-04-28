# 一、PHP 服务端职责

数据库
CREATE TABLE IF NOT EXISTS traffic_platform_platforms (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',
  code VARCHAR(50) NOT NULL UNIQUE COMMENT '平台编码，例如 kkoip、ipweb',
  name VARCHAR(100) NOT NULL COMMENT '平台名称',
  base_url VARCHAR(255) COMMENT '平台API基础地址',
  supports_hourly TINYINT NOT NULL DEFAULT 0 COMMENT '是否支持小时粒度数据',
  enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间'
) COMMENT='三方流量平台配置表';

INSERT INTO traffic_platform_platforms(code, name, base_url, supports_hourly, enabled, created_at, updated_at)
VALUES('kkoip', 'KKOIP', 'https://www.kkoip.com', 0, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name), base_url=VALUES(base_url), supports_hourly=VALUES(supports_hourly), updated_at=NOW();

CREATE TABLE IF NOT EXISTS traffic_platform_accounts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',
  platform_id BIGINT NOT NULL COMMENT '平台ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '平台编码',
  account_name VARCHAR(100) NOT NULL COMMENT '账号名称',
  external_account_id VARCHAR(100) COMMENT '三方平台账号ID，例如 KKOIP accessid',
  credential_json JSON NOT NULL COMMENT '账号凭证JSON，例如 accessid、secret、token',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Shanghai' COMMENT '账号数据时区',
  enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用',
  last_sync_at DATETIME NULL COMMENT '最近同步时间',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',
  INDEX idx_platform_enabled (platform_code, enabled)
) COMMENT='三方流量平台账号表';

CREATE TABLE IF NOT EXISTS traffic_platform_usage_raw (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',
  platform_account_id BIGINT NOT NULL COMMENT '平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '平台编码',
  external_uid VARCHAR(100) COMMENT '三方子账号ID',
  external_username VARCHAR(100) COMMENT '三方子账号名称',
  usage_time VARCHAR(50) NOT NULL COMMENT '三方返回的原始时间',
  geo VARCHAR(100) COMMENT '地区编码',
  region VARCHAR(100) COMMENT '区域名称',
  raw_count VARCHAR(50) COMMENT '三方返回的原始流量值，例如 4.68 GB',
  raw_data JSON NOT NULL COMMENT '完整原始响应数据',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  UNIQUE KEY uk_raw_record (platform_account_id, platform_code, external_uid, usage_time, geo, region)
) COMMENT='三方流量原始数据表';

CREATE TABLE IF NOT EXISTS traffic_platform_usage_hourly (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',
  platform_account_id BIGINT NOT NULL COMMENT '平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '平台编码',
  external_uid VARCHAR(100) COMMENT '三方子账号ID',
  external_username VARCHAR(100) COMMENT '三方子账号名称',
  stat_hour DATETIME NOT NULL COMMENT '统计小时，例如 2026-04-28 10:00:00',
  stat_date DATE NOT NULL COMMENT '统计日期',
  geo VARCHAR(100) COMMENT '地区编码',
  region VARCHAR(100) COMMENT '区域名称',
  traffic_bytes BIGINT NOT NULL DEFAULT 0 COMMENT '该小时流量字节数',
  traffic_mb DECIMAL(20, 6) NOT NULL DEFAULT 0 COMMENT '该小时流量MB',
  traffic_gb DECIMAL(20, 6) NOT NULL DEFAULT 0 COMMENT '该小时流量GB',
  stat_method VARCHAR(30) NOT NULL COMMENT '统计方式：api_hour、diff_from_day',
  is_estimated TINYINT NOT NULL DEFAULT 0 COMMENT '是否为推算数据',
  source_raw_id BIGINT NULL COMMENT '关联原始数据ID',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',
  UNIQUE KEY uk_usage_hourly (platform_account_id, platform_code, external_uid, stat_hour, geo, region),
  INDEX idx_stat_hour (stat_hour),
  INDEX idx_stat_date (stat_date),
  INDEX idx_account_hour (platform_account_id, stat_hour),
  INDEX idx_platform_hour (platform_code, stat_hour)
) COMMENT='三方平台小时流量事实表';

CREATE TABLE IF NOT EXISTS traffic_platform_daily_snapshots (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',
  platform_account_id BIGINT NOT NULL COMMENT '平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '平台编码',
  external_uid VARCHAR(100) COMMENT '三方子账号ID',
  external_username VARCHAR(100) COMMENT '三方子账号名称',
  stat_date DATE NOT NULL COMMENT '统计日期',
  geo VARCHAR(100) COMMENT '地区编码',
  region VARCHAR(100) COMMENT '区域名称',
  total_bytes BIGINT NOT NULL DEFAULT 0 COMMENT '当天累计流量字节数',
  total_gb DECIMAL(20, 6) NOT NULL DEFAULT 0 COMMENT '当天累计流量GB',
  snapshot_time DATETIME NOT NULL COMMENT '快照采集时间',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  INDEX idx_snapshot_lookup (platform_account_id, platform_code, external_uid, stat_date, snapshot_time)
) COMMENT='三方流量日累计快照表，用于小时差值计算';

CREATE TABLE IF NOT EXISTS traffic_platform_sync_jobs (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',
  platform_account_id BIGINT NOT NULL COMMENT '平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '平台编码',
  sync_type VARCHAR(50) NOT NULL COMMENT '同步类型：overview、detail',
  start_time DATETIME NOT NULL COMMENT '同步数据开始时间',
  end_time DATETIME NOT NULL COMMENT '同步数据结束时间',
  status VARCHAR(20) NOT NULL COMMENT '状态：running、success、failed',
  request_params JSON COMMENT '请求参数',
  response_summary JSON COMMENT '响应摘要',
  error_message TEXT COMMENT '错误信息',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',
  INDEX idx_account_status (platform_account_id, status),
  INDEX idx_platform_status (platform_code, status),
  INDEX idx_time_range (start_time, end_time)
) COMMENT='三方流量同步任务记录表';


PHP 负责：

```text
1. 三方平台配置管理
2. 三方平台账号配置管理
3. 查询小时 / 天 / 月流量报表
4. 查询同步任务记录
5. 触发 Go 服务手动同步
6. 给前端提供配置和报表接口
```

Go 负责：

```text
1. 定时拉取第三方平台流量数据
2. 写入原始数据、快照数据、小时事实数据
3. 写入同步任务记录
```

---

# 二、数据库表

## 1. 平台表

```sql
CREATE TABLE traffic_platform_platforms (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',
  code VARCHAR(50) NOT NULL UNIQUE COMMENT '平台编码，例如 kkoip',
  name VARCHAR(100) NOT NULL COMMENT '平台名称',
  base_url VARCHAR(255) COMMENT '平台API基础地址',
  supports_hourly TINYINT NOT NULL DEFAULT 0 COMMENT '是否支持平台原生小时数据',
  enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用',
  remark VARCHAR(255) NULL COMMENT '备注',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间'
) COMMENT='三方流量平台配置表';
```

---

## 2. 平台账号表

```sql
CREATE TABLE traffic_platform_accounts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',
  platform_id BIGINT NOT NULL COMMENT '平台ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '平台编码',
  account_name VARCHAR(100) NOT NULL COMMENT '账号名称',
  external_account_id VARCHAR(100) COMMENT '三方平台账号ID，例如 KKOIP accessid',
  credential_json JSON NOT NULL COMMENT '账号凭证JSON，例如 accessid、secret、token',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Shanghai' COMMENT '账号数据时区',
  enabled TINYINT NOT NULL DEFAULT 1 COMMENT '是否启用',
  last_sync_at DATETIME NULL COMMENT '最近同步时间',
  remark VARCHAR(255) NULL COMMENT '备注',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',

  INDEX idx_platform_enabled (platform_code, enabled)
) COMMENT='三方流量平台账号表';
```

KKOIP 示例：

```json
{
  "accessid": "1",
  "secret": "1234567ABCDEFG"
}
```

---

## 3. 原始数据表

Go 写入，PHP 一般只用于排查查询。

```sql
CREATE TABLE traffic_platform_usage_raw (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',
  platform_account_id BIGINT NOT NULL COMMENT '平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '平台编码',
  external_uid VARCHAR(100) COMMENT '三方子账号ID',
  external_username VARCHAR(100) COMMENT '三方子账号名称',
  usage_time VARCHAR(50) NOT NULL COMMENT '三方返回的原始时间',
  geo VARCHAR(100) COMMENT '地区编码',
  region VARCHAR(100) COMMENT '区域名称',
  raw_count VARCHAR(50) COMMENT '三方返回的原始流量值，例如 4.68 GB',
  raw_data JSON NOT NULL COMMENT '完整原始响应数据',
  created_at DATETIME NOT NULL COMMENT '创建时间',

  UNIQUE KEY uk_raw_record (
    platform_account_id,
    platform_code,
    external_uid,
    usage_time,
    geo,
    region
  )
) COMMENT='三方流量原始数据表';
```

---

## 4. 流量事实表

这是报表核心表。

```sql
CREATE TABLE traffic_platform_usage_stat (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',

  platform_account_id BIGINT NOT NULL COMMENT '平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '平台编码',

  external_uid VARCHAR(100) COMMENT '三方子账号ID',
  external_username VARCHAR(100) COMMENT '三方子账号名称',

  stat_hour DATETIME NOT NULL COMMENT '统计小时，例如 2026-04-28 10:00:00',
  stat_date DATE NOT NULL COMMENT '统计日期',

  geo VARCHAR(100) COMMENT '地区编码',
  region VARCHAR(100) COMMENT '区域名称',

  traffic_bytes BIGINT NOT NULL DEFAULT 0 COMMENT '该小时流量字节数',
  traffic_mb DECIMAL(20, 6) NOT NULL DEFAULT 0 COMMENT '该小时流量MB',
  traffic_gb DECIMAL(20, 6) NOT NULL DEFAULT 0 COMMENT '该小时流量GB',

  stat_method VARCHAR(30) NOT NULL COMMENT '统计方式：api_hour、diff_from_day',
  is_estimated TINYINT NOT NULL DEFAULT 0 COMMENT '是否推算数据',

  source_raw_id BIGINT NULL COMMENT '关联原始数据ID',
  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',

  UNIQUE KEY uk_usage_hourly (
    platform_account_id,
    platform_code,
    external_uid,
    stat_hour,
    geo,
    region
  ),

  INDEX idx_stat_hour (stat_hour),
  INDEX idx_stat_date (stat_date),
  INDEX idx_account_hour (platform_account_id, stat_hour),
  INDEX idx_platform_hour (platform_code, stat_hour)
) COMMENT='三方平台小时流量事实表';
```

说明：

```text
支持小时的平台：
  stat_method = api_hour
  is_estimated = 0

不支持小时的平台：
  stat_method = diff_from_day
  is_estimated = 1
```

---

## 5. 日累计快照表

用于 KKOIP 这种只返回天级累计数据的平台，通过差值推算小时。

```sql
CREATE TABLE traffic_platform_daily_snapshots (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',

  platform_account_id BIGINT NOT NULL COMMENT '平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '平台编码',

  external_uid VARCHAR(100) COMMENT '三方子账号ID',
  external_username VARCHAR(100) COMMENT '三方子账号名称',

  stat_date DATE NOT NULL COMMENT '统计日期',
  geo VARCHAR(100) COMMENT '地区编码',
  region VARCHAR(100) COMMENT '区域名称',

  total_bytes BIGINT NOT NULL DEFAULT 0 COMMENT '当天累计流量字节数',
  total_gb DECIMAL(20, 6) NOT NULL DEFAULT 0 COMMENT '当天累计流量GB',

  snapshot_time DATETIME NOT NULL COMMENT '快照采集时间',
  created_at DATETIME NOT NULL COMMENT '创建时间',

  INDEX idx_snapshot_lookup (
    platform_account_id,
    platform_code,
    external_uid,
    stat_date,
    snapshot_time
  )
) COMMENT='三方流量日累计快照表';
```

---

## 6. 同步任务表

```sql
CREATE TABLE traffic_platform_sync_jobs (
  id BIGINT PRIMARY KEY AUTO_INCREMENT COMMENT '主键ID',

  platform_account_id BIGINT NOT NULL COMMENT '平台账号ID',
  platform_code VARCHAR(50) NOT NULL COMMENT '平台编码',

  sync_type VARCHAR(50) NOT NULL COMMENT '同步类型：overview、detail',
  sync_mode VARCHAR(30) NOT NULL COMMENT '同步模式：api_hour、api_day、diff_from_day',

  start_time DATETIME NOT NULL COMMENT '同步数据开始时间',
  end_time DATETIME NOT NULL COMMENT '同步数据结束时间',

  status VARCHAR(20) NOT NULL COMMENT '状态：running、success、failed',
  request_params JSON COMMENT '请求参数',
  response_summary JSON COMMENT '响应摘要',
  error_message TEXT COMMENT '错误信息',

  created_at DATETIME NOT NULL COMMENT '创建时间',
  updated_at DATETIME NOT NULL COMMENT '更新时间',

  INDEX idx_account_status (platform_account_id, status),
  INDEX idx_platform_status (platform_code, status),
  INDEX idx_time_range (start_time, end_time)
) COMMENT='三方流量同步任务记录表';
```

---

# 三、PHP API 设计

统一前缀：

```text
/api/traffic-platform
```

---

# 四、平台配置 API

## 1. 平台列表

```http
GET /api/traffic-platform/platforms
```

返回：

```json
{
  "code": 0,
  "data": [
    {
      "id": 1,
      "code": "kkoip",
      "name": "KKOIP",
      "base_url": "https://www.kkoip.com",
      "supports_hourly": 0,
      "enabled": 1,
      "remark": "KKOIP 流量平台"
    }
  ]
}
```

---

## 2. 新增平台

```http
POST /api/traffic-platform/platforms
```

请求：

```json
{
  "code": "kkoip",
  "name": "KKOIP",
  "base_url": "https://www.kkoip.com",
  "supports_hourly": 0,
  "enabled": 1,
  "remark": "KKOIP 流量平台"
}
```

---

## 3. 修改平台

```http
PUT /api/traffic-platform/platforms/{id}
```

请求：

```json
{
  "name": "KKOIP",
  "base_url": "https://www.kkoip.com",
  "supports_hourly": 0,
  "enabled": 1,
  "remark": "更新备注"
}
```

---

## 4. 启用 / 禁用平台

```http
PATCH /api/traffic-platform/platforms/{id}/status
```

请求：

```json
{
  "enabled": 0
}
```

---

# 五、平台账号配置 API

## 1. 账号列表

```http
GET /api/traffic-platform/accounts
```

查询参数：

```text
platform_code=kkoip
enabled=1
keyword=main
page=1
page_size=20
```

返回：

```json
{
  "code": 0,
  "data": {
    "list": [
      {
        "id": 1,
        "platform_id": 1,
        "platform_code": "kkoip",
        "platform_name": "KKOIP",
        "account_name": "kkoip-main",
        "external_account_id": "1",
        "timezone": "Asia/Shanghai",
        "enabled": 1,
        "last_sync_at": "2026-04-28 10:00:00",
        "remark": "主账号"
      }
    ],
    "total": 1
  }
}
```

---

## 2. 账号详情

```http
GET /api/traffic-platform/accounts/{id}
```

返回时不要返回密钥明文：

```json
{
  "code": 0,
  "data": {
    "id": 1,
    "platform_code": "kkoip",
    "account_name": "kkoip-main",
    "external_account_id": "1",
    "credential_masked": {
      "accessid": "1",
      "secret": "******"
    },
    "timezone": "Asia/Shanghai",
    "enabled": 1,
    "remark": "主账号"
  }
}
```

---

## 3. 新增账号

```http
POST /api/traffic-platform/accounts
```

请求：

```json
{
  "platform_code": "kkoip",
  "account_name": "kkoip-main",
  "external_account_id": "1",
  "credential": {
    "accessid": "1",
    "secret": "1234567ABCDEFG"
  },
  "timezone": "Asia/Shanghai",
  "enabled": 1,
  "remark": "主账号"
}
```

---

## 4. 修改账号

```http
PUT /api/traffic-platform/accounts/{id}
```

请求：

```json
{
  "account_name": "kkoip-main",
  "external_account_id": "1",
  "credential": {
    "accessid": "1",
    "secret": ""
  },
  "timezone": "Asia/Shanghai",
  "enabled": 1,
  "remark": "更新备注"
}
```

说明：

```text
secret 为空：不修改原 secret
secret 非空：更新 secret
```

---

## 5. 启用 / 禁用账号

```http
PATCH /api/traffic-platform/accounts/{id}/status
```

请求：

```json
{
  "enabled": 0
}
```

---

## 6. 测试账号

```http
POST /api/traffic-platform/accounts/{id}/test
```

PHP 可以转发给 Go 内部接口：

```text
POST http://127.0.0.1:8080/internal/traffic-platform/accounts/{id}/test
```

返回：

```json
{
  "code": 0,
  "msg": "测试成功",
  "data": {
    "balance": 23155,
    "today_use": 44,
    "month_use": 190
  }
}
```

---

# 六、流量查询 API

查询统一基于：

```text
traffic_platform_usage_hourly
```

`granularity` 只作为接口查询参数，不存入事实表。

---

## 1. 小时流量明细

```http
GET /api/traffic-platform/usages/hourly
```

参数：

```text
platform_code=kkoip
account_id=1
external_uid=1234567
start_time=2026-04-28 00:00:00
end_time=2026-04-28 23:59:59
geo=GLOBAL
page=1
page_size=50
```

返回：

```json
{
  "code": 0,
  "data": {
    "list": [
      {
        "stat_hour": "2026-04-28 10:00:00",
        "platform_code": "kkoip",
        "account_name": "kkoip-main",
        "external_uid": "1234567",
        "external_username": "kookeey",
        "geo": "GLOBAL",
        "region": "",
        "traffic_mb": "1024.000000",
        "traffic_gb": "1.000000",
        "stat_method": "diff_from_day",
        "is_estimated": 1
      }
    ],
    "total": 1
  }
}
```

---

## 2. 日流量汇总

```http
GET /api/traffic-platform/usages/daily
```

参数：

```text
platform_code=kkoip
account_id=1
external_uid=1234567
start_date=2026-04-01
end_date=2026-04-28
geo=GLOBAL
```

SQL 思路：

```sql
SELECT
  stat_date,
  platform_account_id,
  platform_code,
  external_uid,
  external_username,
  geo,
  region,
  SUM(traffic_bytes) AS traffic_bytes,
  SUM(traffic_mb) AS traffic_mb,
  SUM(traffic_gb) AS traffic_gb
FROM traffic_platform_usage_hourly
WHERE stat_date BETWEEN ? AND ?
GROUP BY
  stat_date,
  platform_account_id,
  platform_code,
  external_uid,
  external_username,
  geo,
  region
ORDER BY stat_date DESC;
```

---

## 3. 月流量汇总

```http
GET /api/traffic-platform/usages/monthly
```

参数：

```text
platform_code=kkoip
account_id=1
external_uid=1234567
start_month=2026-01
end_month=2026-04
```

SQL 思路：

```sql
SELECT
  DATE_FORMAT(stat_date, '%Y-%m') AS stat_month,
  platform_account_id,
  platform_code,
  external_uid,
  external_username,
  SUM(traffic_bytes) AS traffic_bytes,
  SUM(traffic_mb) AS traffic_mb,
  SUM(traffic_gb) AS traffic_gb
FROM traffic_platform_usage_hourly
WHERE stat_date BETWEEN ? AND ?
GROUP BY
  DATE_FORMAT(stat_date, '%Y-%m'),
  platform_account_id,
  platform_code,
  external_uid,
  external_username
ORDER BY stat_month DESC;
```

---

## 4. 流量趋势

```http
GET /api/traffic-platform/usages/trend
```

参数：

```text
platform_code=kkoip
account_id=1
start_date=2026-04-01
end_date=2026-04-28
dimension=day
```

`dimension` 可选：

```text
hour
day
month
```

返回：

```json
{
  "code": 0,
  "data": [
    {
      "time": "2026-04-01",
      "traffic_gb": "12.500000"
    }
  ]
}
```

---

## 5. 账号流量排行

```http
GET /api/traffic-platform/usages/ranking
```

参数：

```text
platform_code=kkoip
start_date=2026-04-01
end_date=2026-04-28
rank_by=account
limit=20
```

`rank_by` 可选：

```text
account
external_uid
geo
```

---

# 七、同步相关 API

## 1. 手动触发同步

```http
POST /api/traffic-platform/sync
```

请求：

```json
{
  "account_id": 1,
  "platform_code": "kkoip",
  "start_date": "2026-04-28",
  "end_date": "2026-04-28"
}
```

PHP 转发给 Go：

```text
POST http://127.0.0.1:8080/internal/traffic-platform/sync
```

建议 Go 立即返回任务 ID，不要阻塞等待全部同步完成。

---

## 2. 同步任务列表

```http
GET /api/traffic-platform/sync-jobs
```

参数：

```text
platform_code=kkoip
account_id=1
status=failed
start_time=2026-04-01
end_time=2026-04-28
page=1
page_size=20
```

---

## 3. 同步任务详情

```http
GET /api/traffic-platform/sync-jobs/{id}
```

---

# 八、前端页面设计

## 1. 平台管理页

字段：

```text
平台名称
平台编码
API Base URL
是否支持小时数据
状态
备注
操作
```

操作：

```text
新增平台
编辑平台
启用 / 禁用
```

---

## 2. 平台账号配置页

字段：

```text
平台
账号名称
三方账号ID
Access ID
Secret
时区
状态
最近同步时间
备注
```

KKOIP 表单：

```text
平台：KKOIP
账号名称：自定义
Access ID：必填
Secret：必填
时区：默认 Asia/Shanghai
是否启用：是
备注：选填
```

编辑时：

```text
Secret 不展示明文
留空表示不修改
```

操作：

```text
新增账号
编辑账号
启用 / 禁用
测试连接
手动同步
```

---

## 3. 小时流量页面

数据源：

```text
GET /api/traffic-platform/usages/hourly
```

筛选：

```text
平台
平台账号
子账号 UID
地区
开始时间
结束时间
```

展示：

```text
小时
平台
账号
子账号 UID
子账号名称
地区
流量 MB
流量 GB
统计方式
是否推算
```

---

## 4. 日流量页面

数据源：

```text
GET /api/traffic-platform/usages/daily
```

展示：

```text
日期
平台
账号
子账号 UID
子账号名称
地区
流量 GB
```

说明：日流量由小时表聚合得到。

---

## 5. 月流量页面

数据源：

```text
GET /api/traffic-platform/usages/monthly
```

展示：

```text
月份
平台
账号
子账号 UID
子账号名称
流量 GB
```

---

## 6. 趋势图页面

数据源：

```text
GET /api/traffic-platform/usages/trend
```

筛选：

```text
平台
账号
时间范围
维度：小时 / 天 / 月
```

---

## 7. 同步任务页面

数据源：

```text
GET /api/traffic-platform/sync-jobs
```

展示：

```text
任务ID
平台
账号
同步类型
同步模式
开始时间
结束时间
状态
错误信息
创建时间
```

操作：

```text
查看详情
筛选失败任务
重新同步
```

---

# 九、最小接口清单

第一版建议 PHP 实现这些：

```text
GET    /api/traffic-platform/platforms
POST   /api/traffic-platform/platforms
PUT    /api/traffic-platform/platforms/{id}
PATCH  /api/traffic-platform/platforms/{id}/status

GET    /api/traffic-platform/accounts
GET    /api/traffic-platform/accounts/{id}
POST   /api/traffic-platform/accounts
PUT    /api/traffic-platform/accounts/{id}
PATCH  /api/traffic-platform/accounts/{id}/status
POST   /api/traffic-platform/accounts/{id}/test

GET    /api/traffic-platform/usages/hourly
GET    /api/traffic-platform/usages/daily
GET    /api/traffic-platform/usages/monthly
GET    /api/traffic-platform/usages/trend
GET    /api/traffic-platform/usages/ranking

POST   /api/traffic-platform/sync
GET    /api/traffic-platform/sync-jobs
GET    /api/traffic-platform/sync-jobs/{id}
```

核心原则：

```text
Go 写数据。
PHP 管配置和查询。
小时表是唯一事实表。
日、月、趋势、排行全部从小时表聚合。
```
