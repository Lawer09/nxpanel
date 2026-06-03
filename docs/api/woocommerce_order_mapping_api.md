# WooCommerce 商品映射配置接口文档

## 基本说明

- 管理路由前缀：`/api/v3/{secure_path}`
- 鉴权方式：复用现有管理员鉴权中间件
- 接口用途：维护 WooCommerce 商品与本地套餐、周期之间的映射关系，供第三方订单回执转换本地订单时使用

## 查询当前映射

- 接口路径：`GET /api/v3/{secure_path}/woocommerce-order-mapping/fetch`

返回示例：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "mappings": [
      {
        "product_id": 68,
        "plan_id": 2,
        "plan_name": "3 Month Plan",
        "period": "quarterly"
      }
    ],
    "periods": [
      "weekly",
      "monthly",
      "quarterly",
      "half_yearly",
      "yearly",
      "two_yearly",
      "three_yearly",
      "onetime",
      "reset_traffic"
    ]
  }
}
```

字段说明：

- `mappings`：当前所有商品映射配置
- `periods`：系统允许使用的本地订单周期列表

## 保存映射配置

- 接口路径：`POST /api/v3/{secure_path}/woocommerce-order-mapping/save`

请求示例：

```json
{
  "mappings": [
    {
      "product_id": 68,
      "plan_id": 2,
      "period": "quarterly"
    },
    {
      "product_id": 69,
      "plan_id": 3,
      "period": "yearly"
    }
  ]
}
```

请求字段说明：

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| mappings | array | 是 | 完整映射列表 |
| mappings.*.product_id | integer | 是 | WooCommerce 商品 ID |
| mappings.*.plan_id | integer | 是 | 本地套餐 `v2_plan.id` |
| mappings.*.period | string | 是 | 本地订单周期键 |

## 配置规则

- 保存接口采用“整表覆盖”方式：本次提交的 `mappings` 会整体覆盖当前 `woocommerce_product_mappings` 配置。
- 同一个 `product_id` 不允许重复提交，接口会在校验阶段拦截。
- 配置最终保存在 `v2_settings.name = woocommerce_product_mappings` 中。

## 典型用途

- 为 WooCommerce 新商品配置本地套餐映射
- 调整商品对应的续费周期
- 清理或重建 WooCommerce 商品到本地套餐的映射表
