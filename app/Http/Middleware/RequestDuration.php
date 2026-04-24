<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RequestDuration
{
    /**
     * 记录接口请求耗时日志
     */
    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        $response = $next($request);

        $duration = round((microtime(true) - $start) * 1000, 2); // 毫秒
        $status   = $response->getStatusCode();
        $method   = $request->method();
        $uri      = $request->getRequestUri();
        $ip       = $request->getClientIp();
        $userId   = optional($request->user())->id ?? '-';

        $level = $duration > 3000 ? 'warning' : 'info';

        Log::channel('request_duration')->{$level}("API Duration", [
            'method'   => $method,
            'uri'      => $uri,
            'status'   => $status,
            'duration' => "{$duration}ms",
            'ip'       => $ip,
            'user_id'  => $userId,
            'params'   => $request->except(['password', 'token', 'secret']),
        ]);

        // 将耗时写入响应头，方便前端/调试查看
        $response->headers->set('X-Request-Duration', "{$duration}ms");

        return $response;
    }
}
