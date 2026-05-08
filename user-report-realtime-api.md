# userReport 实时查看接口文档

本文档基于当前代码实现，说明管理端实时查看用户上报缓存接口。

## 1. 接口信息

- 方法：`GET`
- 路径：`/api/v3/admin/userReport/realtime`
- 控制器：`App\Http\Controllers\V3\Admin\UserReportController::getRealtime`
- 路由定义：`app/Http/Routes/V3/AdminRoute.php`
- 数据来源缓存 Key：`realtime:user_report:latest`

## 2. 请求参数

全部参数均为 query 参数：

- `page` `int` 可选，默认 `1`，最小值 `1`
- `pageSize` `int` 可选，默认 `50`，范围 `1-200`
- `appId` `string` 可选，最大长度 `255`

参数校验失败时，返回框架默认校验错误响应。

## 3. 处理逻辑

1. 从缓存读取 `realtime:user_report:latest`，默认空数组。
2. 若缓存值不是数组，按空数组处理。
3. 传入 `appId` 时，仅保留 `item.metadata.app_id === appId` 的记录。
4. 按 `page/pageSize` 做内存分页（`array_slice`）。

## 4. 响应结构

接口通过统一 `ok()` 返回，外层结构为：

- `code` `int`
- `msg` `string`
- `data` `object`

其中 `data` 对象字段：

- `data` `array<object>` 当前页记录
- `total` `int` 过滤后的总条数
- `page` `int` 当前页码
- `pageSize` `int` 当前页大小

`data.data[]` 每条记录字段（由用户上报写入缓存时生成）：

- `user_id` `int` 用户 ID
- `ip` `string` 客户端 IP
- `metadata` `object` 上报元信息
- `user_default` `object|array` 用户默认信息（透传）
- `reports` `array<object>` 本次批量上报明细
- `created_at` `string` 写入时间（`Y-m-d H:i:s`）

## 5. 排序与分页说明

- 缓存写入时新数据使用 `array_unshift` 放在头部，默认返回顺序为“最新在前”。
- 本接口不支持自定义排序字段。
- 分页在过滤之后执行。

## 6. 缓存特性

- 缓存由用户侧批量上报接口写入：`POST /api/v3/user/performance/batchReport`
- 单条写入后列表最多保留 `500` 条
- 过期时间：`3600` 秒

## 7. 请求示例

```bash
curl -G 'https://<host>/api/v3/admin/userReport/realtime' \
  --data-urlencode 'page=1' \
  --data-urlencode 'pageSize=20' \
  --data-urlencode 'appId=com.demo.app' \
  -H 'Authorization: Bearer <token>'
```

## 8. 响应示例

```json
{
  "code": 200,
  "msg": "ok",
  "data": {
    "data": [
      {
        "user_id": 10001,
        "ip": "203.0.113.10",
        "metadata": {
          "app_id": "com.demo.app",
          "app_version": "1.0.0",
          "platform": "android",
          "country": "US",
          "timestamp": 1778200000000
        },
        "user_default": {
          "network": "wifi"
        },
        "reports": [
          {
            "node_id": 12,
            "delay": 83,
            "success_rate": 100,
            "status": "success"
          }
        ],
        "created_at": "2026-05-08 10:30:00"
      }
    ],
    "total": 1,
    "page": 1,
    "pageSize": 20
  }
}
```
