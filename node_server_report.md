# 节点实时上报（node_server_report）

## 1. 节点上报内容（来自 `ServerController::report`）

节点调用接口：`POST /api/v2/server/report`

服务端按以下字段处理上报内容：

- `traffic`：流量明细数组，仅接受 `[uid, traffic]` 两元素且都为数字；用于更新在线用户数与流量入账。
- `alive`：存活用户列表；异步投递 `UserAliveSyncJob`。
- `online`：用户实时连接数映射（`uid => conn`）；写入用户连接数缓存。
- `status`：节点负载状态；提取并缓存以下结构：
  - `cpu`
  - `mem.total / mem.used`
  - `swap.total / swap.used`
  - `disk.total / disk.used`
  - `kernel_status`
  - `updated_at`
- `metrics`：节点运行指标（如连接数、吞吐、负载等）；交由 `ServerService::updateMetrics` 处理并写缓存。

同时会更新节点相关状态缓存：

- `SERVER_{TYPE}_LAST_CHECK_AT_{nodeId}`：最后检查时间。
- `SERVER_{TYPE}_ONLINE_USER_{nodeId}`：在线用户数（来自有效 `traffic`）。
- `SERVER_{TYPE}_LAST_PUSH_AT_{nodeId}`：最后流量推送时间。
- `SERVER_{TYPE}_LOAD_STATUS_{nodeId}` / `SERVER_{TYPE}_LAST_LOAD_AT_{nodeId}`：最近负载状态。
- `SERVER_{TYPE}_LOAD_STATUS_HISTORY_{nodeId}`：负载历史（最近 1 小时窗口）。
- `SERVER_{TYPE}_METRICS_{nodeId}` / `SERVER_{TYPE}_METRICS_HISTORY_{nodeId}`：最近指标及历史。

此外，接口会将原始上报快照写入实时队列缓存（用于管理端排查）：

- 缓存 key：`realtime:node_server_report:latest`
- 保留条数：`500`
- 过期时间：`3600s`
- 单条快照结构：
  - `node_id`
  - `node_type`
  - `ip`
  - `traffic`
  - `alive`
  - `online`
  - `status`
  - `metrics`
  - `created_at`

---

## 2. 实时查看接口

- 方法/路径：`POST /api/v3/admin/report/nodeServer/realtime`
- 请求校验：`App\\Http\\Requests\\Admin\\NodeServerRealtimeRequest`

请求参数：

- `page`：可选，默认 `1`
- `pageSize`：可选，默认 `50`，最大 `200`

请求体示例：

```json
{
  "page": 1,
  "pageSize": 50
}
```

返回字段：

- `data`（实时队列内容分页，按时间倒序）
- `total`
- `page`
- `pageSize`
