# 系统设置接口 API

## 基本说明

- 管理端路由前缀：`/api/v2/{secure_path}`
- 系统设置接口前缀：`/api/v2/{secure_path}/config`
- 鉴权中间件：`admin` + `log`
- 控制器：`App\Http\Controllers\V2\Admin\ConfigController`
- 保存参数校验：`App\Http\Requests\Admin\ConfigSave`

`{secure_path}` 来自系统设置：

```php
admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
```

调用管理端接口时需要先完成管理员登录，并携带当前系统使用的管理员鉴权凭证。

## 通用响应格式

管理端配置接口使用 `success()` / `fail()` 响应格式。

成功响应示例：

```json
{
  "status": "success",
  "message": "操作成功",
  "data": {},
  "error": null
}
```

失败响应示例：

```json
{
  "status": "fail",
  "message": "操作失败",
  "data": null,
  "error": null
}
```

## 1. 查询系统设置

- 方法：`GET`
- 路径：`/api/v2/{secure_path}/config/fetch`
- 说明：查询系统设置。未传 `key` 时返回全部配置分组；传入有效 `key` 时只返回指定分组。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| key | string | 否 | 配置分组键名 |

### 支持的 key

| key | 说明 |
| --- | --- |
| invite | 邀请、佣金、提现相关配置 |
| site | 站点基础配置 |
| subscribe | 订阅、套餐变更、流量重置相关配置 |
| frontend | 前端主题配置 |
| server | 节点通信与设备限制配置 |
| email | 邮件服务配置 |
| telegram | Telegram Bot 配置 |
| app | 客户端版本与下载地址配置 |
| safe | 安全、验证码、后台路径、注册限制、密码限制配置 |
| subscribe_template | 订阅模板配置 |

### 请求示例

查询全部配置：

```http
GET /api/v2/{secure_path}/config/fetch
```

只查询站点配置：

```http
GET /api/v2/{secure_path}/config/fetch?key=site
```

### 返回示例

```json
{
  "status": "success",
  "message": "操作成功",
  "data": {
    "site": {
      "logo": "https://example.com/logo.png",
      "force_https": 0,
      "stop_register": 0,
      "app_name": "NxPanel",
      "app_description": "NxPanel is best!",
      "app_url": "https://panel.example.com",
      "subscribe_url": "https://sub.example.com",
      "try_out_plan_id": 0,
      "try_out_hour": 1,
      "tos_url": "https://example.com/tos",
      "currency": "CNY",
      "currency_symbol": "¥",
      "ticket_must_wait_reply": false
    }
  },
  "error": null
}
```

## 2. 保存系统设置

- 方法：`POST`
- 路径：`/api/v2/{secure_path}/config/save`
- 说明：保存系统设置。接口按提交字段逐项保存，未提交字段不会被修改。

### 请求示例

```json
{
  "app_name": "RocketSpaceVPN",
  "app_url": "https://panel.example.com",
  "currency": "USD",
  "currency_symbol": "$",
  "secure_path": "admin-console"
}
```

### 返回示例

```json
{
  "status": "success",
  "message": "操作成功",
  "data": true,
  "error": null
}
```

### 可保存字段

#### 邀请与佣金

| 字段 | 校验规则 | 说明 |
| --- | --- | --- |
| invite_force | 无特殊校验 | 是否强制邀请码 |
| invite_commission | integer\|nullable | 邀请佣金比例 |
| invite_gen_limit | integer\|nullable | 邀请码生成数量限制 |
| invite_never_expire | 无特殊校验 | 邀请码是否永不过期 |
| commission_first_time_enable | 无特殊校验 | 是否仅首单返佣 |
| commission_auto_check_enable | 无特殊校验 | 是否自动审核佣金 |
| commission_withdraw_limit | nullable\|numeric | 提现最低金额 |
| commission_withdraw_method | nullable\|array | 允许的提现方式 |
| withdraw_close_enable | 无特殊校验 | 是否关闭提现 |
| commission_distribution_enable | 无特殊校验 | 是否启用多级分销 |
| commission_distribution_l1 | nullable\|numeric | 一级分销比例 |
| commission_distribution_l2 | nullable\|numeric | 二级分销比例 |
| commission_distribution_l3 | nullable\|numeric | 三级分销比例 |

#### 站点配置

| 字段 | 校验规则 | 说明 |
| --- | --- | --- |
| logo | nullable\|url | 站点 Logo URL |
| force_https | 无特殊校验 | 是否强制 HTTPS |
| stop_register | 无特殊校验 | 是否停止注册 |
| app_name | 无特殊校验 | 应用名称 |
| app_description | 无特殊校验 | 应用描述 |
| app_url | nullable\|url | 站点 URL |
| subscribe_url | nullable | 订阅 URL |
| try_out_enable | 无特殊校验 | 是否启用试用 |
| try_out_plan_id | integer | 试用套餐 ID |
| try_out_hour | numeric | 试用时长，单位小时 |
| tos_url | nullable\|url | 服务条款 URL |
| currency | 无特殊校验 | 币种 |
| currency_symbol | 无特殊校验 | 货币符号 |
| ticket_must_wait_reply | 无特殊校验 | 工单是否必须等待回复 |

#### 订阅配置

| 字段 | 校验规则 | 说明 |
| --- | --- | --- |
| plan_change_enable | 无特殊校验 | 是否允许套餐变更 |
| reset_traffic_method | in:0,1,2,3,4 | 流量重置方式 |
| surplus_enable | 无特殊校验 | 是否启用剩余价值计算 |
| new_order_event_id | 无特殊校验 | 新订单事件 ID |
| renew_order_event_id | 无特殊校验 | 续费订单事件 ID |
| change_order_event_id | 无特殊校验 | 变更套餐订单事件 ID |
| show_info_to_server_enable | 无特殊校验 | 是否向服务端展示用户信息 |
| show_protocol_to_server_enable | 无特殊校验 | 是否向服务端展示协议 |
| subscribe_path | 无特殊校验 | 订阅路径 |
| default_remind_expire | boolean | 默认开启到期提醒 |
| default_remind_traffic | boolean | 默认开启流量提醒 |

#### 节点服务配置

| 字段 | 校验规则 | 说明 |
| --- | --- | --- |
| server_token | nullable\|min:16 | 节点通信密钥 |
| server_pull_interval | integer | 节点拉取间隔 |
| server_push_interval | integer | 节点上报间隔 |
| device_limit_mode | integer | 设备限制模式 |
| server_ws_enable | boolean | 是否启用 WebSocket |
| server_ws_url | nullable\|url | WebSocket 地址 |

#### 前端主题配置

| 字段 | 校验规则 | 说明 |
| --- | --- | --- |
| frontend_theme | 无特殊校验 | 前端主题名称 |
| frontend_theme_sidebar | nullable\|in:dark,light | 侧边栏主题 |
| frontend_theme_header | nullable\|in:dark,light | 顶部栏主题 |
| frontend_theme_color | nullable\|in:default,darkblue,black,green | 主题颜色 |
| frontend_background_url | nullable\|url | 前端背景图 URL |

保存 `frontend_theme` 时会调用 `ThemeService::switch()` 切换主题。

#### 邮件配置

| 字段 | 校验规则 | 说明 |
| --- | --- | --- |
| email_template | 无特殊校验 | 邮件模板目录 |
| email_host | 无特殊校验 | SMTP Host |
| email_port | 无特殊校验 | SMTP Port |
| email_username | 无特殊校验 | SMTP 用户名 |
| email_password | 无特殊校验 | SMTP 密码 |
| email_encryption | 无特殊校验 | SMTP 加密方式 |
| email_from_address | 无特殊校验 | 发件地址 |
| remind_mail_enable | 无特殊校验 | 是否启用提醒邮件 |

#### Telegram 配置

| 字段 | 校验规则 | 说明 |
| --- | --- | --- |
| telegram_bot_enable | 无特殊校验 | 是否启用 Telegram Bot |
| telegram_bot_token | 无特殊校验 | Telegram Bot Token |
| telegram_webhook_url | nullable\|url | Telegram Webhook 基础地址 |
| telegram_discuss_id | 无特殊校验 | Telegram 讨论组 ID |
| telegram_channel_id | 无特殊校验 | Telegram 频道 ID |
| telegram_discuss_link | nullable\|url | Telegram 讨论组链接 |

#### 客户端配置

| 字段 | 校验规则 | 说明 |
| --- | --- | --- |
| windows_version | 无特殊校验 | Windows 客户端版本 |
| windows_download_url | 无特殊校验 | Windows 下载地址 |
| macos_version | 无特殊校验 | macOS 客户端版本 |
| macos_download_url | 无特殊校验 | macOS 下载地址 |
| android_version | 无特殊校验 | Android 客户端版本 |
| android_download_url | 无特殊校验 | Android 下载地址 |

#### 安全配置

| 字段 | 校验规则 | 说明 |
| --- | --- | --- |
| email_whitelist_enable | boolean | 是否启用邮箱后缀白名单 |
| email_whitelist_suffix | nullable\|array | 邮箱后缀白名单 |
| email_gmail_limit_enable | boolean | 是否启用 Gmail 限制 |
| captcha_enable | boolean | 是否启用验证码 |
| captcha_type | in:recaptcha,turnstile,recaptcha-v3 | 验证码类型 |
| recaptcha_enable | boolean | 旧版兼容字段 |
| recaptcha_key | 无特殊校验 | reCAPTCHA Secret |
| recaptcha_site_key | 无特殊校验 | reCAPTCHA Site Key |
| recaptcha_v3_secret_key | 无特殊校验 | reCAPTCHA v3 Secret |
| recaptcha_v3_site_key | 无特殊校验 | reCAPTCHA v3 Site Key |
| recaptcha_v3_score_threshold | numeric\|min:0\|max:1 | reCAPTCHA v3 分数阈值 |
| turnstile_secret_key | 无特殊校验 | Turnstile Secret |
| turnstile_site_key | 无特殊校验 | Turnstile Site Key |
| email_verify | bool | 是否启用邮箱验证 |
| safe_mode_enable | boolean | 是否启用安全模式 |
| register_limit_by_ip_enable | boolean | 是否启用 IP 注册限制 |
| register_limit_count | integer | IP 注册限制次数 |
| register_limit_expire | integer | IP 注册限制时间 |
| secure_path | min:8\|regex:/^[\w-]*$/ | 管理后台安全路径 |
| password_limit_enable | boolean | 是否启用密码错误限制 |
| password_limit_count | integer | 密码错误限制次数 |
| password_limit_expire | integer | 密码错误限制时间 |

#### 订阅模板

| 字段 | 校验规则 | 说明 |
| --- | --- | --- |
| subscribe_template_singbox | nullable | Sing-box 订阅模板 |
| subscribe_template_clash | nullable | Clash 订阅模板 |
| subscribe_template_clashmeta | nullable | Clash Meta 订阅模板 |
| subscribe_template_stash | nullable | Stash 订阅模板 |
| subscribe_template_surge | nullable | Surge 订阅模板 |
| subscribe_template_surfboard | nullable | Surfboard 订阅模板 |

订阅模板字段不会写入普通系统设置表，而是通过 `SubscribeTemplate::setContent()` 保存到订阅模板存储。

## 3. 获取邮件模板列表

- 方法：`GET`
- 路径：`/api/v2/{secure_path}/config/getEmailTemplate`
- 说明：读取 `resources/views/mail/` 下的邮件模板文件列表。

### 返回示例

```json
{
  "status": "success",
  "message": "操作成功",
  "data": [
    "notify.blade.php"
  ],
  "error": null
}
```

## 4. 获取主题模板列表

- 方法：`GET`
- 路径：`/api/v2/{secure_path}/config/getThemeTemplate`
- 说明：读取 `public/theme/` 下的主题目录或文件列表。

### 返回示例

```json
{
  "status": "success",
  "message": "操作成功",
  "data": [
    "NxPanel"
  ],
  "error": null
}
```

## 5. 设置 Telegram Webhook

- 方法：`POST`
- 路径：`/api/v2/{secure_path}/config/setTelegramWebhook`
- 说明：根据 `telegram_webhook_url` 或 `app_url` 拼接 `/api/v1/guest/telegram/webhook`，并调用 Telegram 设置 Webhook。

### 请求参数

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| telegram_bot_token | string | 否 | 本次请求使用的 Bot Token；为空时使用当前系统设置中的 `telegram_bot_token` |

### 返回示例

```json
{
  "status": "success",
  "message": "操作成功",
  "data": {
    "success": true,
    "webhook_url": "https://panel.example.com/api/v1/guest/telegram/webhook?access_token=md5-token",
    "webhook_base_url": "https://panel.example.com"
  },
  "error": null
}
```

## 6. 测试发送邮件

- 方法：`POST`
- 路径：`/api/v2/{secure_path}/config/testSendMail`
- 说明：向当前管理员邮箱发送测试邮件。

### 返回示例

```json
{
  "data": {}
}
```

注意：该接口当前直接返回 `response(['data' => $mailLog])`，不是统一的 `success()` 响应格式。

## 7. 前台公开配置接口

以下接口也读取系统设置，但只返回前台需要的公开配置，不提供保存能力。

### 游客公共配置

- 方法：`GET`
- 路径：`/api/v1/guest/comm/config`
- 控制器：`App\Http\Controllers\V1\Guest\CommController::config`
- 鉴权：无需登录

返回字段包括：

| 字段 | 说明 |
| --- | --- |
| tos_url | 服务条款 URL |
| is_email_verify | 是否启用邮箱验证 |
| is_invite_force | 是否强制邀请码 |
| email_whitelist_suffix | 邮箱后缀白名单；未启用时为 `0` |
| is_captcha | 是否启用验证码 |
| captcha_type | 验证码类型 |
| recaptcha_site_key | reCAPTCHA Site Key |
| recaptcha_v3_site_key | reCAPTCHA v3 Site Key |
| recaptcha_v3_score_threshold | reCAPTCHA v3 分数阈值 |
| turnstile_site_key | Turnstile Site Key |
| app_description | 应用描述 |
| app_url | 应用 URL |
| logo | Logo URL |
| is_recaptcha | 旧版兼容字段，同 `is_captcha` |

### 用户公共配置

- 方法：`GET`
- 路径：`/api/v1/user/comm/config`
- 控制器：`App\Http\Controllers\V1\User\CommController::config`
- 鉴权：需要用户登录

返回字段包括：

| 字段 | 说明 |
| --- | --- |
| is_telegram | 是否启用 Telegram Bot |
| telegram_discuss_link | Telegram 讨论组链接 |
| stripe_pk | Stripe 公钥 |
| withdraw_methods | 允许的提现方式 |
| withdraw_close | 是否关闭提现 |
| currency | 币种 |
| currency_symbol | 货币符号 |
| commission_distribution_enable | 是否启用多级分销 |
| commission_distribution_l1 | 一级分销比例 |
| commission_distribution_l2 | 二级分销比例 |
| commission_distribution_l3 | 三级分销比例 |

## 注意事项

- 当前系统设置管理接口在 V2 Admin 路由下，未在 V3 Admin 路由中重复暴露。
- 保存接口只会保存请求中通过校验的字段；未提交字段保持原值。
- 涉及密钥、Token、SMTP 密码、验证码 Secret 的字段不要写入公开文档示例或日志。
- 修改 `secure_path` 后，后续管理端接口路径会随之变化，前端需要同步使用新路径。
