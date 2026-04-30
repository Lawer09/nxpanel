<?php

namespace App\Http\Controllers\V1\Passport;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Services\Auth\LoginService;
use App\Services\Auth\MailLinkService;
use App\Services\Auth\RegisterService;
use App\Services\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected MailLinkService $mailLinkService;
    protected RegisterService $registerService;
    protected LoginService $loginService;

    public function __construct(
        MailLinkService $mailLinkService,
        RegisterService $registerService,
        LoginService $loginService
    ) {
        $this->mailLinkService = $mailLinkService;
        $this->registerService = $registerService;
        $this->loginService = $loginService;
    }

    /**
     * 通过邮件链接登录
     */
    public function loginWithMailLink(Request $request)
    {
        $params = $request->validate([
            'email' => 'required|email:strict',
            'redirect' => 'nullable'
        ]);

        [$success, $result] = $this->mailLinkService->handleMailLink(
            $params['email'],
            $request->input('redirect')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success($result);
    }

    /**
     * 用户注册
     */
    public function register(AuthRegister $request)
    {
        [$success, $result] = $this->registerService->register($request);

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
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
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }

    /**
     * 通过token登录
     */
    public function token2Login(Request $request)
    {
        // 处理直接通过token重定向
        if ($token = $request->input('token')) {
            $redirect = '/#/login?verify=' . $token . '&redirect=' . ($request->input('redirect', 'dashboard'));

            return redirect()->to(
                admin_setting('app_url')
                ? admin_setting('app_url') . $redirect
                : url($redirect)
            );
        }

        // 处理通过验证码登录
        if ($verify = $request->input('verify')) {
            $userId = $this->mailLinkService->handleTokenLogin($verify);

            if (!$userId) {
                return response()->json([
                    'message' => __('Token error')
                ], 400);
            }

            $user = \App\Models\User::find($userId);

            if (!$user) {
                return response()->json([
                    'message' => __('User not found')
                ], 400);
            }

            $authService = new AuthService($user);

            return response()->json([
                'data' => $authService->generateAuthData()
            ]);
        }

        return response()->json([
            'message' => __('Invalid request')
        ], 400);
    }

    /**
     * 获取快速登录URL
     */
    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');

        if (!$authorization) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED
            ], 401);
        }

        $user = AuthService::findUserByBearerToken($authorization);

        if (!$user) {
            return response()->json([
                'message' => ResponseEnum::CLIENT_HTTP_UNAUTHORIZED_EXPIRED
            ], 401);
        }

        $url = $this->loginService->generateQuickLoginUrl($user, $request->input('redirect'));
        return $this->success($url);
    }

    /**
     * 忘记密码处理
     */
    public function forget(AuthForget $request)
    {
        [$success, $result] = $this->loginService->resetPassword(
            $request->input('email'),
            $request->input('email_code'),
            $request->input('password')
        );

        if (!$success) {
            return $this->fail($result);
        }

        return $this->success(true);
    }


    /**
     * 通过AID快捷登录（自动注册）
     * 账号为 {aid}@apple.com，密码为 {aid}
     * 用户不存在时自动创建
     */
    public function loginByAid(Request $request)
    {
        $request->validate([
            'aid' => 'required|string|min:1|max:255',
            'metadata' => 'nullable|array',
            'metadata.app_id' => 'nullable|string|max:255',
            'metadata.app_version' => 'nullable|string|max:50',
            'metadata.platform' => 'nullable|string|max:100',
            'metadata.brand' => 'nullable|string|max:100',
            'metadata.country' => 'nullable|string|max:100',
            'metadata.city' => 'nullable|string|max:100',
            'metadata.device_id' => 'nullable|string|max:255',
            'metadata.channel' => 'nullable|string|max:100',
            'metadata.channelType' => 'nullable|string|in:paid,organic,unknown',
            'metadata.channel_type' => 'nullable|string|in:paid,organic,unknown',
            'metadata.utm_source' => 'nullable|string|max:255',
            'metadata.utm_medium' => 'nullable|string|max:255',
            'metadata.utm_campaign' => 'nullable|string|max:255',
            'metadata.raw_referrer' => 'nullable|string|max:2048',
            'metadata.click_ts' => 'nullable|integer|min:0',
            'metadata.install_begin_ts' => 'nullable|integer|min:0',
        ], [
            'aid.required' => 'aid参数不能为空',
        ]);

        [$success, $result] = $this->loginService->loginByAid(
            $request->input('aid'),
            $request->input('metadata')
        );

        if (!$success) {
            return $this->fail($result);
        }

        $authService = new AuthService($result);
        return $this->success($authService->generateAuthData());
    }
}
