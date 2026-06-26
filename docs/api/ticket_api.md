# 工单接口

## 创建工单

- Method: `POST`
- URI: `/api/v1/user/ticket/save`
- URI: `/api/v3/user/ticket/save`
- Middleware: `user`

### 请求参数

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| subject | string | 是 | 工单主题 |
| level | integer | 是 | 工单等级，可选值：`0`、`1`、`2` |
| message | string | 是 | 首条工单消息 |
| personal_email | string | 否 | 个人联系邮箱，必须为合法邮箱，最长 255 字符 |

### 行为说明

- 同一用户可以同时创建多个未关闭工单。
- `personal_email` 只保存到工单主表 `v2_ticket.personal_email`，不会修改用户账号邮箱。
- 创建成功后仍按现有接口约定返回布尔成功结果。

### 请求示例

```json
{
  "subject": "节点连接异常",
  "level": 1,
  "message": "请协助排查节点连接失败问题",
  "personal_email": "user.personal@example.com"
}
```

## 查询工单

- Method: `GET`
- URI: `/api/v1/user/ticket/fetch`
- URI: `/api/v3/user/ticket/fetch`
- Middleware: `user`

### 返回字段补充

用户端工单列表和详情返回新增 `personal_email` 字段；未填写时为 `null`。
