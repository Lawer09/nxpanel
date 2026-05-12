# 项目管理接口文档

## 模块说明

项目管理模块提供项目的 CRUD 能力，包括项目列表查询、详情查看、新增、修改和状态变更。

**调用方**：管理后台前端。

---

## 接口列表

| 接口名称 | 请求方法 | 请求路径 | 说明 |
|---------|---------|---------|------|
| 项目列表 | GET | /api/v3/admin/projects | 分页查询项目列表 |
| 项目详情 | GET | /api/v3/admin/projects/{id} | 按 ID 获取项目详情 |
| 新增项目 | POST | /api/v3/admin/projects | 创建新项目 |
| 修改项目 | PUT | /api/v3/admin/projects/{id} | 更新项目信息 |
| 变更状态 | PATCH | /api/v3/admin/projects/{id}/status | 修改项目状态 |

---

## 接口详情

### 1. 项目列表

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/api/v3/admin/projects`
- 权限要求：`admin`
- 使用场景：管理后台项目列表页

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|--------|------|------|--------|------|
| keyword | string | 否 | - | 搜索关键词，匹配 project_code / project_name |
| status | string | 否 | - | 状态筛选，可选值 `active` / `inactive` / `archived` |
| ownerId | integer | 否 | - | 按 owner_id 筛选 |
| page | integer | 否 | 1 | 当前页 |
| pageSize | integer | 否 | 20 | 每页数量，最大 200 |

#### 返回字段

| 字段名 | 类型 | 说明 |
|--------|------|------|
| code | integer | 状态码，0 表示成功 |
| msg | string | 返回消息 |
| data.page | integer | 当前页 |
| data.pageSize | integer | 每页数量 |
| data.total | integer | 总条数 |
| data.data | array | 项目列表 |
| data.data[].id | integer | 项目 ID |
| data.data[].projectCode | string | 项目代号 |
| data.data[].projectName | string | 项目名称 |
| data.data[].ownerName | string | 负责人 |
| data.data[].department | string | 部门 |
| data.data[].status | string | 状态：active / inactive / archived |
| data.data[].remark | string | 备注 |
| data.data[].createdAt | datetime | 创建时间 |
| data.data[].updatedAt | datetime | 更新时间 |

#### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": {
        "page": 1,
        "pageSize": 20,
        "total": 100,
        "data": [
            {
                "id": 1,
                "projectCode": "PROJ-001",
                "projectName": "示例项目",
                "ownerName": "张三",
                "department": "技术部",
                "status": "active",
                "remark": "备注信息",
                "createdAt": "2026-05-01T10:00:00.000000Z",
                "updatedAt": "2026-05-12T08:00:00.000000Z"
            }
        ]
    }
}
```

#### 错误码

无额外业务错误码。

---

### 2. 项目详情

#### 请求信息

- 请求方法：`GET`
- 请求路径：`/api/v3/admin/projects/{id}`
- 权限要求：`admin`
- 使用场景：管理后台项目详情页

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|--------|------|------|--------|------|
| id | integer | 是（路径） | - | 项目 ID |

#### 返回字段

同项目列表中的项目对象结构。

#### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": {
        "id": 1,
        "projectCode": "PROJ-001",
        "projectName": "示例项目",
        "ownerName": "张三",
        "department": "技术部",
        "status": "active",
        "remark": "备注信息",
        "createdAt": "2026-05-01T10:00:00.000000Z",
        "updatedAt": "2026-05-12T08:00:00.000000Z"
    }
}
```

#### 错误码

| code | msg | 说明 |
|------|-----|------|
| 404 | 项目不存在 | 指定 ID 的项目不存在 |

---

### 3. 新增项目

#### 请求信息

- 请求方法：`POST`
- 请求路径：`/api/v3/admin/projects`
- 权限要求：`admin`
- 使用场景：管理后台新增项目

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|--------|------|------|--------|------|
| projectCode | string | 是 | - | 项目代号，最大 100 字符，不可重复 |
| projectName | string | 是 | - | 项目名称，最大 100 字符 |
| ownerName | string | 否 | - | 负责人，最大 100 字符 |
| department | string | 否 | - | 部门，最大 100 字符 |
| status | string | 否 | active | 状态，可选值 `active` / `inactive` / `archived` |
| remark | string | 否 | - | 备注，最大 255 字符 |

#### 返回字段

| 字段名 | 类型 | 说明 |
|--------|------|------|
| code | integer | 状态码，0 表示成功 |
| msg | string | 返回消息 |
| data | object | 创建的项目对象（结构同列表项） |

#### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": {
        "id": 2,
        "projectCode": "PROJ-002",
        "projectName": "新项目",
        "ownerName": "李四",
        "department": "运营部",
        "status": "active",
        "remark": null,
        "createdAt": "2026-05-12T08:00:00.000000Z",
        "updatedAt": "2026-05-12T08:00:00.000000Z"
    }
}
```

#### 错误码

| code | msg | 说明 |
|------|-----|------|
| 422 | 项目代号已存在 | projectCode 重复 |

---

### 4. 修改项目

#### 请求信息

- 请求方法：`PUT`
- 请求路径：`/api/v3/admin/projects/{id}`
- 权限要求：`admin`
- 使用场景：管理后台编辑项目

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|--------|------|------|--------|------|
| id | integer | 是（路径） | - | 项目 ID |
| projectName | string | 否 | - | 项目名称，最大 100 字符 |
| ownerName | string | 否 | - | 负责人，最大 100 字符 |
| department | string | 否 | - | 部门，最大 100 字符 |
| status | string | 否 | - | 状态，可选值 `active` / `inactive` / `archived` |
| remark | string | 否 | - | 备注，最大 255 字符 |

#### 返回字段

| 字段名 | 类型 | 说明 |
|--------|------|------|
| code | integer | 状态码，0 表示成功 |
| msg | string | 返回消息 |
| data | object | 更新后的项目对象（结构同列表项） |

#### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": {
        "id": 1,
        "projectCode": "PROJ-001",
        "projectName": "修改后的名称",
        "ownerName": "张三",
        "department": "技术部",
        "status": "active",
        "remark": "更新了备注",
        "createdAt": "2026-05-01T10:00:00.000000Z",
        "updatedAt": "2026-05-12T08:30:00.000000Z"
    }
}
```

#### 错误码

| code | msg | 说明 |
|------|-----|------|
| 404 | 项目不存在 | 指定 ID 的项目不存在 |

---

### 5. 变更状态

#### 请求信息

- 请求方法：`PATCH`
- 请求路径：`/api/v3/admin/projects/{id}/status`
- 权限要求：`admin`
- 使用场景：管理后台快速切换项目状态

#### 请求参数

| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|--------|------|------|--------|------|
| id | integer | 是（路径） | - | 项目 ID |
| status | string | 是（body） | - | 目标状态：`active` / `inactive` / `archived` |

#### 返回字段

| 字段名 | 类型 | 说明 |
|--------|------|------|
| code | integer | 状态码，0 表示成功 |
| msg | string | 返回消息 |
| data | object | 更新后的项目对象（结构同列表项） |

#### 返回示例

```json
{
    "code": 0,
    "msg": "操作成功",
    "data": {
        "id": 1,
        "projectCode": "PROJ-001",
        "projectName": "示例项目",
        "ownerName": "张三",
        "department": "技术部",
        "status": "archived",
        "remark": "备注信息",
        "createdAt": "2026-05-01T10:00:00.000000Z",
        "updatedAt": "2026-05-12T09:00:00.000000Z"
    }
}
```

#### 错误码

| code | msg | 说明 |
|------|-----|------|
| 404 | 项目不存在 | 指定 ID 的项目不存在 |

#### 相关文件

- `app/Http/Controllers/V3/Admin/ProjectController.php`
- `app/Http/Requests/Admin/ProjectFetchRequest.php`
- `app/Http/Requests/Admin/ProjectSaveRequest.php`
- `app/Http/Requests/Admin/ProjectUpdateRequest.php`
- `app/Http/Requests/Admin/ProjectUpdateStatusRequest.php`
- `app/Services/ProjectService.php`
- `app/Http/Resources/ProjectResource.php`
- `app/Http/Routes/V3/AdminRoute.php`
