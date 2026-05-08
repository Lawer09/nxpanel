# 节点实时上报（node_server_report）

## 1. 目标

- 为节点上报接口 `POST /api/v2/server/report` 提供可排查的实时快照视图。
- 参照用户实时上报能力，保留最近上报队列并支持按节点分页查看。

---

## 2. 上报缓存队列

- 写入时机：节点调用 `POST /api/v2/server/report`。
- 队列缓存 key：`realtime:node_server_report:latest`。
- 队列长度：固定保留最近 `500` 条（超过后自动截断）。
- 过期时间：`3600s`。

单条快照字段：

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

## 3. 查询接口

- 方法/路径：`POST /api/v3/admin/report/nodeServer/realtime`
- 请求校验：`App\\Http\\Requests\\Admin\\NodeServerRealtimeRequest`

请求参数：

- `nodeId`：必填，节点 ID
- `page`：可选，默认 `1`
- `pageSize`：可选，默认 `50`，最大 `200`

请求体示例：

```json
{
  "nodeId": 12,
  "page": 1,
  "pageSize": 50
}
```

返回字段：

- `nodeId`
- `nodeName`
- `nodeType`
- `data`（该节点命中的实时上报快照列表，按时间倒序）
- `total`
- `page`
- `pageSize`

---

## 4. 校对建议

1. 先查本接口确认节点原始上报是否到达缓存队列。
2. 再对照主报表接口 `POST /api/v3/admin/report/node/query` 与子表校对接口 `POST /api/v3/admin/report/node/subtable/query`。
3. 若实时有数据但聚合缺失，重点检查对应 5 分钟桶聚合任务执行情况。
