<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\AppClient;
use Closure;

class AppAuth
{
    /**
     * 校验应用身份，支持 header 与请求参数两种传递方式。
     *
     * Header 优先：X-App-Id / X-App-Token
     * 参数兼容：appId / appToken / app_id / app_token
     */
    public function handle($request, Closure $next)
    {
        $appId = $request->header('X-App-Id')
            ?? $request->input('appId', $request->input('app_id'));
        $appToken = $request->header('X-App-Token')
            ?? $request->input('appToken', $request->input('app_token'));

        if (!$appId || !$appToken) {
            throw new ApiException('app credential is missing', 403);
        }

        /** @var AppClient|null $client */
        $client = AppClient::query()
            ->where('app_id', $appId)
            ->where('app_token', $appToken)
            ->first();

        if (!$client) {
            throw new ApiException('app credential is invalid', 403);
        }

        if (!$client->is_enabled) {
            throw new ApiException('app is disabled', 403);
        }

        $request->attributes->set('appClient', $client);

        return $next($request);
    }
}
