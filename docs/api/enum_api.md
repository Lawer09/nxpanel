# Enum API 文档

本文档说明管理端枚举相关接口。

---

## 1. 通用枚举

- **方法/路径**：`GET /api/v3/admin/{securePath}/enum/options`
- **控制器**：`EnumController::getOptions`

说明：支持 `servers`、`server_groups`、`server_types`、`plans` 等枚举。

---

## 2. AppID 枚举（项目绑定）

- **方法/路径**：`GET /api/v3/admin/{securePath}/enum/app-ids`
- **控制器**：`EnumController::getAppIds`
- **Request**：`EnumAppIdsRequest`
- **数据来源**：`project_user_app_map.app_id`（去重）

### 2.1 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| keyword | string | 否 | 关键字过滤（模糊匹配 appId） |

### 2.2 返回字段（data[]）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| appId | string | 项目绑定 AppID |
| value | string | 同 appId（前端枚举值） |
| label | string | 同 appId（前端枚举显示） |

### 2.3 返回示例

```json
[
  {
    "appId": "com.demo.app",
    "value": "com.demo.app",
    "label": "com.demo.app"
  }
]
```
