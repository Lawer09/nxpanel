<?php

namespace App\Http\Controllers\V3\Passport;

use App\Http\Controllers\V1\Passport\AuthController as V1AuthController;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;


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

        $authService = new AuthService($result);
        return $this->ok($authService->generateAuthData());
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
            'metadata.app_version' => 'nullable|string|max:50',
            'metadata.platform' => 'nullable|string|max:100',
            'metadata.brand' => 'nullable|string|max:100',
            'metadata.country' => 'nullable|string|max:100',
            'metadata.city' => 'nullable|string|max:100',
            'metadata.device_id' => 'nullable|string|max:255',
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
            $metadata   
        );

        if (!$success) {
            return $this->error($result);
        }

        $authService = new AuthService($result);
        return $this->ok($authService->generateAuthData());
    }
}
