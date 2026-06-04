# Queue Task API

## 基本信息

- 管理端路由前缀：`/api/v2/{secure_path}`
- 队列任务接口前缀：`/api/v2/{secure_path}/system`
- 鉴权中间件：`admin` + `log`
- 控制器：`App\Http\Controllers\V2\Admin\SystemController`

`{secure_path}` 来源于系统配置：

```php
admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
```

所有接口统一返回：

```json
{
  "status": "success",
  "message": "操作成功",
  "data": {}
}
```

## 1. 查询系统调度与 Horizon 状态

- 方法：`GET`
- 路径：`/api/v2/{secure_path}/system/getSystemStatus`
- 说明：返回调度器最近运行时间、调度器状态、Horizon 总体状态。

### 返回字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| schedule | bool | 最近 120 秒内是否有调度器心跳 |
| horizon | bool | Horizon 是否存在可用 master supervisor，且未处于 paused |
| schedule_last_runtime | mixed | 最近一次调度心跳时间 |

## 2. 查询 Horizon 队列统计

- 方法：`GET`
- 路径：`/api/v2/{secure_path}/system/getQueueStats`
- 说明：返回 Horizon 全局失败任务数、最近任务数、进程数、等待时间等摘要统计。

### 返回字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| failedJobs | int | 最近失败任务数 |
| jobsPerMinute | mixed | Horizon 每分钟处理任务数 |
| pausedMasters | int | 暂停中的 master supervisor 数量 |
| periods | object | Horizon recent/failed 保留周期 |
| processes | int | 当前总 worker 进程数 |
| queueWithMaxRuntime | mixed | 运行时间最高的队列 |
| queueWithMaxThroughput | mixed | 吞吐最高的队列 |
| recentJobs | int | 最近任务数 |
| status | bool | Horizon 状态 |
| wait | array | 队列等待时间快照 |

## 3. 查询 Horizon 队列负载

- 方法：`GET`
- 路径：`/api/v2/{secure_path}/system/getQueueWorkload`
- 说明：返回 Horizon 记录的各队列 workload 原始数据，适合判断某个队列是否积压。

## 4. 查询 Horizon Master Supervisor 列表

- 方法：`GET`
- 路径：`/api/v2/{secure_path}/system/getQueueMasters`
- 说明：直接代理 Horizon 的 MasterSupervisorController，用于查看 master/supervisor 列表与运行状态。

## 5. 查询 Horizon 失败任务列表

- 方法：`GET`
- 路径：`/api/v2/{secure_path}/system/getHorizonFailedJobs`
- 说明：分页返回 Horizon 失败任务列表。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| page | int | 否 | 页码，默认 1 |
| pageSize | int | 否 | 每页条数，默认 20，最小 10 |

## 6. 查询 send_webhook 专用任务诊断

- 方法：`GET`
- 路径：`/api/v2/{secure_path}/system/getSendWebhookTasks`
- 说明：专门用于排查自动化 webhook 通知未送达问题。接口直接读取 Redis 中 `send_webhook` 队列的 pending / delayed / reserved 状态，并结合 `failed_jobs` 返回失败记录。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| sampleLimit | int | 否 | 每类 Redis 队列样本条数，默认 10，范围 1-50 |
| failedPage | int | 否 | 失败任务页码，默认 1 |
| failedPageSize | int | 否 | 失败任务每页条数，默认 10，范围 1-100 |

### 请求示例

```http
GET /api/v2/{secure_path}/system/getSendWebhookTasks?sampleLimit=5&failedPage=1&failedPageSize=10
```

### 返回字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| queue | string | 固定为 `send_webhook` |
| summary | object | send_webhook 队列摘要 |
| pendingJobs | array | Redis List 中待消费任务样本 |
| delayedJobs | array | Redis ZSET 中延迟任务样本 |
| reservedJobs | array | Redis ZSET 中保留任务样本 |
| failedJobs | object | 数据库失败任务分页结果 |

`summary` 字段说明：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| horizon | bool | Horizon 总体状态 |
| redisPrefix | string | Redis Key 前缀 |
| keys | object | 当前队列使用的 Redis 物理 Key |
| pendingCount | int | `queues:send_webhook` 中待执行任务数量 |
| delayedCount | int | `queues:send_webhook:delayed` 中延迟任务数量 |
| reservedCount | int | `queues:send_webhook:reserved` 中保留任务数量 |
| failedCount | int | `failed_jobs` 中 `queue=send_webhook` 的失败记录数 |
| workload | array | Horizon workload 中匹配 `send_webhook` 的队列项 |

单条 `pendingJobs[]` / `delayedJobs[]` / `reservedJobs[]` 样本字段说明：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| uuid | string | Laravel 队列任务 UUID |
| displayName | string | 任务展示名，通常为 `App\\Jobs\\SendWebhookJob` |
| job | string | 底层执行器类 |
| attempts | int | 已尝试次数 |
| maxTries | int/null | 最大重试次数 |
| timeout | int/null | 超时秒数 |
| backoff | mixed | 重试退避配置 |
| commandName | string | 序列化命令名 |
| rawPayload | string | 原始 payload 截断摘要 |
| position | int | 仅 pending 队列返回，表示列表中的顺序 |
| available_at | string | 仅 delayed 队列返回，表示计划可执行时间 |
| available_at_timestamp | int | 仅 delayed 队列返回，Unix 时间戳 |
| reserved_at | string | 仅 reserved 队列返回，表示保留时间 |
| reserved_at_timestamp | int | 仅 reserved 队列返回，Unix 时间戳 |

`failedJobs` 字段说明：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| page | int | 当前页 |
| pageSize | int | 每页条数 |
| total | int | 总失败记录数 |
| data | array | 失败任务列表 |

单条 `failedJobs.data[]` 字段说明：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | int | failed_jobs 主键 |
| connection | string | 队列连接名 |
| queue | string | 队列名，固定为 `send_webhook` |
| failedAt | string | 失败时间 |
| displayName | string | 任务展示名 |
| job | string | 底层执行器类 |
| attempts | int | 失败时 attempts 值 |
| exceptionSummary | string | 异常首行摘要 |
| payload | object | 解析后的任务 payload 摘要 |

### 返回示例

```json
{
  "status": "success",
  "message": "操作成功",
  "data": {
    "queue": "send_webhook",
    "summary": {
      "horizon": true,
      "redisPrefix": "nxpanel_database_",
      "keys": {
        "pending": "nxpanel_database_queues:send_webhook",
        "delayed": "nxpanel_database_queues:send_webhook:delayed",
        "reserved": "nxpanel_database_queues:send_webhook:reserved"
      },
      "pendingCount": 0,
      "delayedCount": 1,
      "reservedCount": 0,
      "failedCount": 2,
      "workload": []
    },
    "pendingJobs": [],
    "delayedJobs": [
      {
        "uuid": "1e1b5ab0-3d97-4db2-8c56-7f3f4d09d001",
        "displayName": "App\\Jobs\\SendWebhookJob",
        "job": "Illuminate\\Queue\\CallQueuedHandler@call",
        "attempts": 0,
        "maxTries": 3,
        "timeout": 30,
        "backoff": [5, 15, 60],
        "commandName": "App\\Jobs\\SendWebhookJob",
        "rawPayload": "{\"uuid\":\"1e1b5ab0-3d97...\"}",
        "available_at": "2026-06-04 18:20:30",
        "available_at_timestamp": 1780568430
      }
    ],
    "reservedJobs": [],
    "failedJobs": {
      "page": 1,
      "pageSize": 10,
      "total": 2,
      "data": [
        {
          "id": 15,
          "connection": "redis",
          "queue": "send_webhook",
          "failedAt": "2026-06-04 18:19:01",
          "displayName": "App\\Jobs\\SendWebhookJob",
          "job": "Illuminate\\Queue\\CallQueuedHandler@call",
          "attempts": 3,
          "exceptionSummary": "RuntimeException: SendWebhookJob failed: HTTP 500 method=POST url=https://example.com/webhook",
          "payload": {
            "uuid": "8ae5f7b2-38aa-44c2-9d18-22e8f0ecf001",
            "displayName": "App\\Jobs\\SendWebhookJob",
            "job": "Illuminate\\Queue\\CallQueuedHandler@call",
            "attempts": 3,
            "maxTries": 3,
            "timeout": 30,
            "backoff": [5, 15, 60],
            "commandName": "App\\Jobs\\SendWebhookJob",
            "rawPayload": "{\"uuid\":\"8ae5f7b2-38aa...\"}"
          }
        }
      ]
    }
  }
}
```

### 排查建议

1. `pendingCount > 0` 且长期不下降：通常表示 `send_webhook` 队列没有消费者或 worker 已卡死。
2. `delayedCount > 0`：表示 webhook 任务还在延迟窗口内，当前设计默认延迟 30 秒合并发送。
3. `reservedCount > 0` 且持续不释放：通常表示 worker 正在执行或执行超时未正确确认。
4. `failedCount > 0`：优先查看 `failedJobs.data[].exceptionSummary`，通常能直接看出目标地址、HTTP 状态码或超时错误。
