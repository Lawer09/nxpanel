<?php

namespace App\Http\Controllers\V3\Passport;

use App\Http\Controllers\V1\Passport\AuthController as V1AuthController;
use App\Services\AdSpendAdminUserSyncService;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use Illuminate\Support\Facades\Log;


class AuthController extends V1AuthController
{
    /**
     * 用户注册
     */
    public function register(AuthRegister $request)
    {
        [$success, $result] = $this->registerService->register($request);

        if (!$success) {
            return $this->error($result);
        }

        $authService = new AuthService($result);
        return $this->ok($authService->generateAuthData());
    }

    /**
     * 用户登录
     */
    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        [$success, $result] = $this->loginService->login($email, $password);

        if (!$success) {
            return $this->error($result);
        }

        $data = $this->buildPasswordLoginData($result);
        if ((bool) $result->is_admin) {
            $data['ad_spend_platform_login'] = null;
            try {
                $adSpendAdminUserSyncService = app(AdSpendAdminUserSyncService::class);
                $data['ad_spend_platform_login'] = $adSpendAdminUserSyncService->rememberUserLoginData(
                    (int) $result->id,
                    $adSpendAdminUserSyncService->loginUser($email, $password)
                );
            } catch (\Throwable $e) {
                Log::warning('Ad spend platform user login failed', [
                    'user_id' => $result->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->ok($data);
    }


    /**
     * Refresh local login data by bearer token without the user's password.
     */
    public function refresh(Request $request): JsonResponse
    {
        $authorization = $this->resolveRefreshAuthorization($request);
        if (!$authorization) {
            return $this->error([401, 'Unauthorized']);
        }

        $user = AuthService::findUserByBearerToken($authorization);
        if (!$user) {
            return $this->error([401, 'Unauthorized']);
        }
        if ($user->banned) {
            return $this->error([400, __('Your account has been suspended')]);
        }

        $data = $this->buildPasswordLoginData($user);
        if ((bool) $user->is_admin) {
            $adSpendAdminUserSyncService = app(AdSpendAdminUserSyncService::class);
            $remoteToken = $this->resolveAdSpendPlatformToken($request);

            $data['ad_spend_platform_login'] = $remoteToken
                ? $adSpendAdminUserSyncService->rememberTokenLoginData((int) $user->id, $remoteToken)
                : $adSpendAdminUserSyncService->cachedUserLoginData((int) $user->id);
        }

        return $this->ok($data);
    }

    private function resolveRefreshAuthorization(Request $request): ?string
    {
        $authorization = $request->header('authorization')
            ?: $request->input('auth_data')
            ?: $request->input('authorization')
            ?: $request->input('token');

        if (!is_string($authorization) || trim($authorization) === '') {
            return null;
        }

        $authorization = trim($authorization);

        return str_starts_with($authorization, 'Bearer ')
            ? $authorization
            : 'Bearer ' . $authorization;
    }

    private function resolveAdSpendPlatformToken(Request $request): ?string
    {
        $token = $request->input('ad_spend_platform_token')
            ?: $request->input('adSpendPlatformToken');

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
     * 通过AID快捷登录（自动注册）- V3
     *
     * POST /api/v3/passport/auth/loginByAid
     *
     * 账号为 {aid}@apple.com，密码为 {aid}
     * 用户不存在时自动创建
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function loginByAid(Request $request): JsonResponse
    {
        $request->validate([
            'aid' => 'required|string|min:1|max:255',
            'metadata' => 'required|array',
            'metadata.app_id' => 'required|string|max:255',
            'metadata.package_name' => 'nullable|string|max:191',
            'metadata.packageName' => 'nullable|string|max:191',
            'metadata.app_version' => 'nullable|string|max:50',
            'metadata.platform' => 'nullable|string|max:100',
            'metadata.brand' => 'nullable|string|max:100',
            'metadata.country' => 'nullable|string|max:100',
            'metadata.city' => 'nullable|string|max:100',
            'metadata.device_id' => 'nullable|string|max:255',
            'metadata.ip' => 'nullable|ip',
            'channel' => 'nullable|array',
            'channel.channel_type' => 'nullable|string|in:paid,organic,unknown',
            'channel.channelType' => 'nullable|string|in:paid,organic,unknown',
            'channel.utm_source' => 'nullable|string|max:255',
            'channel.utm_medium' => 'nullable|string|max:255',
            'channel.utm_campaign' => 'nullable|string|max:255',
            'channel.raw_referrer' => 'nullable|string|max:2048',
            'channel.click_ts' => 'nullable|integer|min:0',
            'channel.install_begin_ts' => 'nullable|integer|min:0',
        ], [
            'aid.required' => 'aid参数不能为空',
        ]);

        $metadata = $request->input('metadata', []);
        $channel = $request->input('channel', null);
        if (is_array($channel) && !empty($channel)) {
            $metadata['channel'] = $channel;
        }
        [$success, $result] = $this->loginService->loginByAid(
            $request->input('aid'),
            $metadata,
            true
        );

        if (!$success) {
            return $this->error($result);
        }

        $authService = new AuthService($result);
        $data = $authService->generateAuthData();
        $data['is_ban'] = (bool) $result->banned;

        return $this->ok($data);
    }
}
