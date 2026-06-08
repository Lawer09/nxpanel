# PB 点击归因回调接口

## 基本说明

- 接口路径：`GET /pb/com.jkcl.zwx.vpn`
- 鉴权要求：无
- 用途：接收第三方 PB 点击归因回调并落库
- 请求参数：`clickid`、`deviceid`
- 去重规则：按 `clickid` 去重，同一个 `clickid` 重复回调不再新增记录

## 接口详情

### 请求信息

- 请求方法：`GET`
- 请求路径：`/pb/com.jkcl.zwx.vpn`
- 鉴权要求：无
- 使用场景：第三方广告归因平台将点击参数回传到当前系统，用于保存点击与设备的关联关系

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| clickid | string | 是 | 第三方点击 ID，系统按该字段做幂等去重 |
| deviceid | string | 是 | 设备 ID |

### 请求示例

```http
GET /pb/com.jkcl.zwx.vpn?clickid=click_001&deviceid=device_001
```

### 成功返回示例

首次写入：

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "stored": true,
    "duplicate": false,
    "packageName": "com.jkcl.zwx.vpn",
    "clickid": "click_001",
    "deviceid": "device_001"
  }
}
```

重复回调：

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    "stored": false,
    "duplicate": true,
    "packageName": "com.jkcl.zwx.vpn",
    "clickid": "click_001",
    "deviceid": "device_002"
  }
}
```

### 校验失败示例

```json
{
  "code": 422,
  "msg": "The clickid field is required.",
  "data": {
    "clickid": [
      "The clickid field is required."
    ]
  }
}
```

## 落库说明

- 数据表：`postback_receipts`
- 固定包名：`com.jkcl.zwx.vpn`
- 存储字段：
  - `package_name`
  - `clickid`
  - `deviceid`
  - `request_ip`
  - `user_agent`
  - `created_at`
  - `updated_at`
- 唯一索引：`clickid`

## 处理规则

- 首次接收到某个 `clickid` 时，新增一条记录并返回 `stored=true`
- 已存在相同 `clickid` 时，不覆盖原记录，直接返回 `duplicate=true`
- 即使重复请求中的 `deviceid` 与首次请求不同，也不会更新数据库中的原始记录

## 相关文件

- `routes/web.php`
- `app/Http/Controllers/Postback/PostbackController.php`
- `app/Http/Requests/Postback/PostbackStoreRequest.php`
- `app/Services/PostbackReceiptService.php`
- `app/Models/PostbackReceipt.php`
- `database/migrations/2026_06_08_120000_create_postback_receipts_table.php`
