<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IpInfoService
{
    const IPINFO_API_URL = 'https://ipinfo.io';
    const REQUEST_TIMEOUT = 10;

    /**
     * 获取IP详细信息
     */
    public static function getIpInfo($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \Exception('IP地址格式错误', 422);
        }

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->get(self::IPINFO_API_URL . "/{$ip}/json");
            
            if (!$response->successful()) {
                throw new \Exception('获取IP信息失败，请稍后重试', 500);
            }

            $data = $response->json();

            if (!isset($data['ip'])) {
                throw new \Exception('IP信息不存在', 400);
            }

            return self::normalizeData($data);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('IP Info Connection Failed', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            throw new \Exception('网络连接失败，请检查网络', 500);
        } catch (\Exception $e) {
            Log::error('IP Info Request Failed', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 规范化返回数据
     */
    private static function normalizeData($data)
    {
        return [
            'ip' => $data['ip'] ?? null,
            'hostname' => $data['hostname'] ?? null,
            'city' => $data['city'] ?? null,
            'region' => $data['region'] ?? null,
            'country' => $data['country'] ?? null,
            'loc' => $data['loc'] ?? null,
            'org' => $data['org'] ?? null,
            'postal' => $data['postal'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'readme' => $data['readme'] ?? 'https://ipinfo.io/missingauth'
        ];
    }
}