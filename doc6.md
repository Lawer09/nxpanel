# 节点探测错误分析接口说明（V3）

本文用于前端对接节点探测排障相关查询接口（含失败率排行、伪成功识别、错误码分布）。

## 0. 基础信息

- 接口前缀：`/v3/`
- 管理端接口，需管理员登录态
- 统一返回：

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {}
}
```

---

## 1. 数据口径说明（前端必读）

1) 探测阶段 `probeStage`
- `node_connect`：连接节点过程（客户端可能上报 `tunnel_establish`，后端已统一归一到 `node_connect`）
- `post_connect_probe`：连上后可用性验证（如 `generate_204`）

2) 探测状态 `status`
- `success` / `failed` / `timeout` / `cancelled`

3) 节点标识
- 内部节点：有 `nodeId`（`>0`）
- 外部节点：客户端可能仅上报 `nodeIp` / `vpnNodeIp`
- 后端会尝试把外部 `nodeIp` 映射到 `v2_server.host` 对应的 `nodeId`（含缓存）

4) `includeExternal` 参数
- 默认 `false`：只展示 `nodeId > 0` 的节点
- `true`：包含未映射成功的外部节点（`nodeId=0`，通常依赖 `nodeIp` 展示）

---

## 2. 探测错误分布

`GET /performance/probeErrors`

用于查看错误码、阶段、状态分布。

### Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| nodeId | int | 否 | 节点 ID |
| dateFrom | string | 否 | 开始日期，`YYYY-MM-DD` |
| dateTo | string | 否 | 结束日期，`YYYY-MM-DD` |
| clientCountry | string | 否 | 客户端国家（2位） |
| platform | string | 否 | 平台 |
| appId | string | 否 | 应用 ID |
| appVersion | string | 否 | 应用版本 |
| probeStage | string | 否 | `node_connect` / `post_connect_probe` / `tunnel_establish` |
| status | string | 否 | `success` / `failed` / `timeout` / `cancelled` |
| errorCode | string | 否 | 错误码 |
| groupBy | string | 否 | `node` / `error_code` / `stage` / `status` / `stage_error`（默认） |
| includeExternal | bool | 否 | 是否包含外部节点，默认 `false` |
| pageSize | int | 否 | 默认 `50`，最大 `200` |

### data 示例（`groupBy=stage_error`）

```json
{
  "data": [
    {
      "probeStage": "node_connect",
      "errorCode": "tcp_connect_timeout",
      "totalCount": 3812
    },
    {
      "probeStage": "post_connect_probe",
      "errorCode": "post_connect_probe_failed",
      "totalCount": 968
    }
  ],
  "total": 2,
  "page": 1,
  "pageSize": 50,
  "groupBy": "stage_error"
}
```

---

## 3. 节点失败率排行

`GET /performance/nodeFailureRank`

口径：仅按 `success + failed` 计算失败率。

`failureRate = failedCount / (successCount + failedCount) * 100`

### Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 开始日期 |
| dateTo | string | 否 | 结束日期 |
| clientCountry | string | 否 | 客户端国家 |
| platform | string | 否 | 平台 |
| appId | string | 否 | 应用 ID |
| appVersion | string | 否 | 应用版本 |
| probeStage | string | 否 | `node_connect` / `post_connect_probe` / `tunnel_establish` |
| minTotal | int | 否 | 最小样本量，默认 `20` |
| includeExternal | bool | 否 | 是否包含外部节点，默认 `false` |
| pageSize | int | 否 | 默认 `50` |

### data 示例

```json
{
  "data": [
    {
      "nodeId": 1024,
      "nodeIp": "edge-us-1.example.com",
      "nodeName": "US-EDGE-1",
      "successCount": 12230,
      "failedCount": 3180,
      "totalCount": 15410,
      "failureRate": 20.64
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 50,
  "minTotal": 20
}
```

---

## 4. 伪成功识别报表

`GET /performance/pseudoSuccess`

口径：
- 分母：`node_connect + success`
- 分子：`post_connect_probe + failed`

`pseudoSuccessRate = postConnectFailedCount / nodeConnectSuccessCount * 100`

### Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| dateFrom | string | 否 | 开始日期 |
| dateTo | string | 否 | 结束日期 |
| clientCountry | string | 否 | 客户端国家 |
| platform | string | 否 | 平台 |
| appId | string | 否 | 应用 ID |
| appVersion | string | 否 | 应用版本 |
| minConnected | int | 否 | 最小连接成功样本量，默认 `20` |
| includeExternal | bool | 否 | 是否包含外部节点，默认 `false` |
| pageSize | int | 否 | 默认 `50` |

### data 示例

```json
{
  "data": [
    {
      "nodeId": 1024,
      "nodeIp": "edge-us-1.example.com",
      "nodeName": "US-EDGE-1",
      "nodeConnectSuccessCount": 9800,
      "postConnectFailedCount": 1260,
      "pseudoSuccessRate": 12.86
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 50,
  "minConnected": 20
}
```

---

## 5. 前端接入建议

1) 页面结构建议
- Tab1：`nodeFailureRank`（快速定位高失败节点）
- Tab2：`pseudoSuccess`（识别“看起来连上但不可用”节点）
- Tab3：`probeErrors`（看错误码细分与阶段）

2) 默认筛选建议
- 时间默认最近 24 小时或 3 天
- 默认 `probeStage=node_connect` 查看“连不上”的主问题
- 伪成功页固定看 `post_connect_probe` 口径（接口已内置）

3) 防止误判建议
- 保持 `minTotal>=20`、`minConnected>=20`
- 小样本节点不直接用于自动下线/降权

4) 外部节点展示建议
- 常规运营页：`includeExternal=false`
- 外部质量专项页：`includeExternal=true`，并优先展示 `nodeIp`

5) 联动建议
- 在排行列表点击节点后，跳转 `probeErrors?groupBy=stage_error&nodeId=xxx`
- 若 `nodeId=0`（未映射），使用 `includeExternal=true` + 时间范围定位问题

---

## 6. 常用查询示例

1) 最近3天节点失败率排行（连接阶段）

`GET /performance/nodeFailureRank?dateFrom=2026-04-27&dateTo=2026-04-29&probeStage=node_connect&minTotal=30`

2) 最近24小时伪成功排行

`GET /performance/pseudoSuccess?dateFrom=2026-04-29&dateTo=2026-04-29&minConnected=30`

3) 某节点错误码拆分

`GET /performance/probeErrors?nodeId=1024&groupBy=stage_error&dateFrom=2026-04-29&dateTo=2026-04-29`

---

## 6.1 节点流量报表（新增）

`GET /performance/nodeTraffic`

用于查看客户端上报的节点流量与使用时长数据。

### 上报字段口径

- `arise_timestamp`：用户结束使用时间戳（优先用于归桶）
- `vpn_user_time`：使用时长
  - 当前兼容格式：`1时21分1秒` / `1h21m1s` / 纯数字秒
  - 后续若客户端统一为秒，后端已兼容
- `vpn_user_traffic`：使用流量
  - 兼容：`MB` / `GB` / `KB` / 纯数字（按 MB）

### Query 参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| nodeId | int | 否 | 节点 ID |
| dateFrom | string | 否 | 开始日期 |
| dateTo | string | 否 | 结束日期 |
| clientCountry | string | 否 | 客户端国家 |
| platform | string | 否 | 平台 |
| appId | string | 否 | 应用 ID |
| appVersion | string | 否 | 应用版本 |
| groupBy | string | 否 | `node` / `date` / `hour`，默认 `node` |
| includeExternal | bool | 否 | 是否包含外部节点，默认 `false` |
| pageSize | int | 否 | 默认 `50`，最大 `200` |

### data 示例（`groupBy=node`）

```json
{
  "data": [
    {
      "nodeId": 1024,
      "nodeIp": "edge-us-1.example.com",
      "nodeName": "US-EDGE-1",
      "totalUsageSeconds": 128903,
      "totalUsageMb": 91823.125,
      "reportCount": 673
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 50,
  "groupBy": "node"
}
```

前端展示建议：

- `totalUsageSeconds` 建议格式化成 `X时Y分Z秒`
- 流量展示建议：`>=1024MB` 时转为 `GB`
- 运营看板默认按 `totalUsageMb` 倒序

---

## 7. 前端 TypeScript 字段字典（建议）

```ts
type ProbeStage = 'node_connect' | 'post_connect_probe'
type ProbeStatus = 'success' | 'failed' | 'timeout' | 'cancelled'

type GroupBy = 'node' | 'error_code' | 'stage' | 'status' | 'stage_error'

interface ApiEnvelope<T> {
  code: number
  msg: string
  data: T
}

interface PageData<T> {
  data: T[]
  total: number
  page: number
  pageSize: number
}

interface ProbeErrorsItem {
  nodeId?: number
  errorCode?: string | null
  probeStage?: ProbeStage
  status?: ProbeStatus
  totalCount: number
}

interface ProbeErrorsResp extends PageData<ProbeErrorsItem> {
  groupBy: GroupBy
}

interface NodeFailureRankItem {
  nodeId: number
  nodeIp: string | null
  nodeName: string
  successCount: number
  failedCount: number
  totalCount: number
  failureRate: number
}

interface NodeFailureRankResp extends PageData<NodeFailureRankItem> {
  minTotal: number
}

interface PseudoSuccessItem {
  nodeId: number
  nodeIp: string | null
  nodeName: string
  nodeConnectSuccessCount: number
  postConnectFailedCount: number
  pseudoSuccessRate: number
}

interface PseudoSuccessResp extends PageData<PseudoSuccessItem> {
  minConnected: number
}

interface ProbeCommonQuery {
  dateFrom?: string
  dateTo?: string
  clientCountry?: string
  platform?: string
  appId?: string
  appVersion?: string
  includeExternal?: boolean
  pageSize?: number
}

interface ProbeErrorsQuery extends ProbeCommonQuery {
  nodeId?: number
  probeStage?: ProbeStage | 'tunnel_establish'
  status?: ProbeStatus
  errorCode?: string
  groupBy?: GroupBy
}

interface NodeFailureRankQuery extends ProbeCommonQuery {
  probeStage?: ProbeStage | 'tunnel_establish'
  minTotal?: number
}

interface PseudoSuccessQuery extends ProbeCommonQuery {
  minConnected?: number
}

interface NodeTrafficItemByNode {
  nodeId: number
  nodeIp: string | null
  nodeName: string
  totalUsageSeconds: number
  totalUsageMb: number
  reportCount: number
}

interface NodeTrafficItemByDate {
  date: string
  totalUsageSeconds: number
  totalUsageMb: number
  reportCount: number
}

interface NodeTrafficItemByHour {
  date: string
  hour: number
  totalUsageSeconds: number
  totalUsageMb: number
  reportCount: number
}

interface NodeTrafficQuery extends ProbeCommonQuery {
  nodeId?: number
  groupBy?: 'node' | 'date' | 'hour'
}
```

### 7.1 渲染建议（字段兜底）

- `nodeName` 直接展示；若为空可回退 `nodeIp`，再回退 `nodeId`
- 百分比字段（`failureRate`、`pseudoSuccessRate`）统一保留 2 位小数并加 `%`
- 当 `nodeId=0` 时，前端视为外部节点；可加标签 `external`

### 7.2 查询参数建议（避免踩坑）

- `includeExternal` 不传等价于 `false`
- `probeStage=tunnel_establish` 可以传，后端会自动归一到 `node_connect`
- 日期建议始终成对传 `dateFrom + dateTo`，便于和其他图表对齐
