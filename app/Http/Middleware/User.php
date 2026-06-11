<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AuthService;
use Closure;
use Illuminate\Support\Facades\Auth;

class User
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!Auth::guard('sanctum')->check()) {
            $authorization = $this->resolveAuthorization($request);
            $user = $authorization ? AuthService::findUserByBearerToken($authorization) : null;

            if (!$user) {
                throw new ApiException('未登录或登陆已过期', 403);
            }

            Auth::guard('sanctum')->setUser($user);
            Auth::setUser($user);
        }

        return $next($request);
    }

    /**
     * Resolve Bearer token from request parameters for clients that cannot set headers.
     */
    private function resolveAuthorization($request): ?string
    {
        $authorization = $request->input('auth_data')
            ?? $request->input('authorization')
            ?? $request->header('authorization');

        if (!$authorization) {
            return null;
        }

        $authorization = trim((string) $authorization);
        if ($authorization === '') {
            return null;
        }

        return str_starts_with($authorization, 'Bearer ')
            ? $authorization
            : 'Bearer ' . $authorization;
    }
}
