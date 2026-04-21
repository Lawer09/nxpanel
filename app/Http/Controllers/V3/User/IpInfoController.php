<?php

namespace App\Http\Controllers\V3\User;

use App\Http\Controllers\Controller;
use App\Services\IpInfoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class IpInfoController extends Controller
{
    /**
     * 获取客户端 IP 信息
     *
     * GET /user/performance/clientIpInfo
     * Query params:
     *   ip string optional 指定查询 IP，不传则使用客户端 IP
     */
    public function getClientIpInfo(Request $request): JsonResponse
    {
        $request->validate([
            'ip' => 'nullable|ip',
        ]);

        $ip = $request->input('ip') ?: $request->getClientIp();

        try {
            $data = IpInfoService::getIpInfo($ip);
            return $this->ok($data);
        } catch (\Exception $e) {
            Log::error('Get client IP info failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            $code = $e->getCode() ?: 500;
            return $this->error([$code, $e->getMessage() ?: '获取IP信息失败']);
        }
    }
}
