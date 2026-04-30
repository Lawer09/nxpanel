<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;

class LoginService
{
    /**
     * 处理用户登录
     *
     * @param string $email 用户邮箱
     * @param string $password 用户密码
     * @return array [成功状态, 用户对象或错误信息]
     */
    public function login(string $email, string $password): array
    {
        // 检查密码错误限制
        if ((int) admin_setting('password_limit_enable', true)) {
            $passwordErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int) admin_setting('password_limit_count', 10)) {
                return [
                    false,
                    [
                        429,
                        __('There are too many password errors, please try again after :minute minutes.', [
                            'minute' => admin_setting('password_limit_expire', 60)
                        ])
                    ]
                ];
            }
        }

        // 查找用户
        $user = User::where('email', $email)->first();
        if (!$user) {
            return [false, [400, __('Incorrect email or password')]];
        }

        // 验证密码
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $password,
                $user->password
            )
        ) {
            // 增加密码错误计数
            if ((int) admin_setting('password_limit_enable', true)) {
                $passwordErrorCount = (int) Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int) $passwordErrorCount + 1,
                    60 * (int) admin_setting('password_limit_expire', 60)
                );
            }
            return [false, [400, __('Incorrect email or password')]];
        }

        // 检查账户状态
        if ($user->banned) {
            return [false, [400, __('Your account has been suspended')]];
        }

        // 更新最后登录时间
        $user->last_login_at = time();
        $user->save();

        HookManager::call('user.login.after', $user);
        return [true, $user];
    }

    /**
     * 通过AID快捷登录（自动注册）
     * 账号为 {aid}@apple.com，密码为 {aid}
     * 用户不存在时自动创建
     *
     * @param string $aid
     * @return array [成功状态, 用户对象或错误信息]
     */
    public function loginByAid(string $aid, ?array $metadata = null): array
    {
        $email = $aid . '@apple.com';
        $password = $aid;
        $normalizedMetadata = $this->normalizeAidMetadata($metadata);

        $user = User::where('email', $email)->first();
        $created = false;
        $duplicateOnCreate = false;

        if (!$user) {
            // 用户不存在，自动创建
            try {
                $userService = app(\App\Services\UserService::class);
                $user = $userService->createUser([
                    'email'    => $email,
                    'password' => $password,
                    'plan_id'  => 1,
                    'register_metadata' => $normalizedMetadata,
                ]);

                if (!$user->save()) {
                    return [false, [500, 'User creation failed']];
                }

                $created = true;
            } catch (\Illuminate\Database\QueryException $e) {
                $errorCode = $e->errorInfo[1] ?? null;
                if ($errorCode === 1062) {
                    \Illuminate\Support\Facades\Log::warning('loginByAid duplicate user on create', [
                        'aid' => $aid,
                        'email' => $email,
                    ]);
                    $duplicateOnCreate = true;
                    $user = User::where('email', $email)->first();
                }

                if (!$user) {
                    return [false, [500, 'User creation failed']];
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('loginByAid create user failed', [
                    'error' => $e->getMessage(),
                    'aid'   => $aid,
                ]);
                return [false, [500, 'User creation failed']];
            }
        }

        if (!$created) {
            if ($duplicateOnCreate) {
                return [false, [409, 'AID already registered. Please log in or use a different AID.']];
            }
            // 用户已存在，验证密码
            if (!Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $password,
                $user->password
            )) {
                return [false, [400, __('Incorrect email or password')]];
            }

            // 检查账户状态
            if ($user->banned) {
                return [false, [400, __('Your account has been suspended')]];
            }

            if (!empty($normalizedMetadata)) {
                $currentMetadata = is_array($user->register_metadata) ? $user->register_metadata : [];
                $user->register_metadata = array_merge($currentMetadata, $normalizedMetadata);
            }
        }

        // 更新最后登录时间
        $user->last_login_at = time();
        $user->save();

        return [true, $user];
    }

    private function normalizeAidMetadata(?array $metadata): array
    {
        if (!is_array($metadata)) {
            return [];
        }

        $allowedScalarKeys = [
            'app_id',
            'app_version',
            'platform',
            'brand',
            'country',
            'city',
            'device_id',
            'channel',
        ];

        $result = [];
        foreach ($allowedScalarKeys as $key) {
            if (!array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];
            if ($value === null) {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized === '') {
                continue;
            }
            $result[$key] = $normalized;
        }

        // 兼容 metadata.channel 对象
        $channel = $metadata['channel'] ?? null;
        if (is_array($channel)) {
            $nestedChannelType = $channel['channel_type'] ?? ($channel['channelType'] ?? null);
            if (is_string($nestedChannelType) && trim($nestedChannelType) !== '') {
                $result['channel_type'] = trim($nestedChannelType);
            }

            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'raw_referrer'] as $key) {
                $value = $channel[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $result[$key] = trim($value);
                }
            }

            foreach (['click_ts', 'install_begin_ts'] as $key) {
                $value = $channel[$key] ?? null;
                if ($value !== null && $value !== '' && is_numeric($value)) {
                    $result[$key] = (int) $value;
                }
            }
        }

        return $result;
    }

    /**
     * 处理密码重置
     *
     * @param string $email 用户邮箱
     * @param string $emailCode 邮箱验证码
     * @param string $password 新密码
     * @return array [成功状态, 结果或错误信息]
     */
    public function resetPassword(string $email, string $emailCode, string $password): array
    {
        // 检查重置请求限制
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $email);
        $forgetRequestLimit = (int) Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            return [false, [429, __('Reset failed, Please try again later')]];
        }

        // 验证邮箱验证码
        if ((string) Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email)) !== (string) $emailCode) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit ? $forgetRequestLimit + 1 : 1, 300);
            return [false, [400, __('Incorrect email verification code')]];
        }

        // 查找用户
        $user = User::where('email', $email)->first();
        if (!$user) {
            return [false, [400, __('This email is not registered in the system')]];
        }

        // 更新密码
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;

        if (!$user->save()) {
            return [false, [500, __('Reset failed')]];
        }
        
        // 密码重置后清除所有 session，强制重新登录  
        $user->tokens()->delete();

        HookManager::call('user.password.reset.after', $user);

        // 清除邮箱验证码
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $email));

        return [true, true];
    }


    /**
     * 生成临时登录令牌和快速登录URL
     *
     * @param User $user 用户对象
     * @param string $redirect 重定向路径
     * @return string|null 快速登录URL
     */
    public function generateQuickLoginUrl(User $user, ?string $redirect = null): ?string
    {
        if (!$user || !$user->exists) {
            return null;
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);

        Cache::put($key, $user->id, 60);

        $redirect = $redirect ?: 'dashboard';
        $loginRedirect = '/#/login?verify=' . $code . '&redirect=' . rawurlencode($redirect);

        if (admin_setting('app_url')) {
            $url = admin_setting('app_url') . $loginRedirect;
        } else {
            $url = url($loginRedirect);
        }

        return $url;
    }
}
