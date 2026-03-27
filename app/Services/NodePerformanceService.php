<?php

namespace App\Services;

use App\Models\NodePerformanceReport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NodePerformanceService
{
    /**
     * 获取客户端IP信息
     */
    public static function getClientIpInfo($ip)
    {
        try {
            $response = Http::timeout(5)->get("https://ipinfo.io/{$ip}/json");
            
            if ($response->successful()) {
                $data = $response->json();
                return [
                    'country' => $data['country'] ?? null,
                    'city' => $data['city'] ?? null,
                    'isp' => $data['org'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to fetch IP info', ['ip' => $ip, 'error' => $e->getMessage()]);
        }

        return [
            'country' => null,
            'city' => null,
            'isp' => null,
        ];
    }

    /**
     * 保存性能上报数据
     */
    public static function reportPerformance($userId, $nodeId, $data, $clientIp, $request)
    {
        // 获取IP地址信息
        $ipInfo = self::getClientIpInfo($clientIp);

        // 提取用户代理和平台信息
        $userAgent = $request->header('User-Agent', '');
        $platform = self::detectPlatform($userAgent);

        try {
            $report = NodePerformanceReport::create([
                'user_id' => $userId,
                'node_id' => $nodeId,
                'delay' => (int) ($data['delay'] ?? 0),
                'success_rate' => (int) ($data['success_rate'] ?? 0),
                'client_ip' => $clientIp,
                'client_country' => $ipInfo['country'],
                'client_city' => $ipInfo['city'],
                'client_isp' => $ipInfo['isp'],
                'user_agent' => $userAgent,
                'platform' => $platform,
                'app_version' => $data['app_version'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            Log::info('Node performance reported', [
                'user_id' => $userId,
                'node_id' => $nodeId,
                'delay' => $data['delay'],
                'success_rate' => $data['success_rate'],
            ]);

            return $report;
        } catch (\Exception $e) {
            Log::error('Failed to save performance report', [
                'user_id' => $userId,
                'node_id' => $nodeId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 批量上报性能数据
     */
    public static function batchReportPerformance($userId, $nodeReports, $clientIp, $request)
    {
        $ipInfo = self::getClientIpInfo($clientIp);
        $userAgent = $request->header('User-Agent', '');
        $platform = self::detectPlatform($userAgent);

        $results = [];

        try {
            foreach ($nodeReports as $report) {
                $data = [
                    'user_id' => $userId,
                    'node_id' => (int) $report['node_id'],
                    'delay' => (int) ($report['delay'] ?? 0),
                    'success_rate' => (int) ($report['success_rate'] ?? 0),
                    'client_ip' => $clientIp,
                    'client_country' => $ipInfo['country'],
                    'client_city' => $ipInfo['city'],
                    'client_isp' => $ipInfo['isp'],
                    'user_agent' => $userAgent,
                    'platform' => $platform,
                    'app_version' => $report['app_version'] ?? null,
                    'metadata' => $report['metadata'] ?? null,
                ];

                $result = NodePerformanceReport::create($data);
                $results[] = $result;
            }

            Log::info('Batch performance reported', [
                'user_id' => $userId,
                'count' => count($results),
                'client_ip' => $clientIp,
            ]);

            return $results;
        } catch (\Exception $e) {
            Log::error('Failed to save batch performance report', [
                'user_id' => $userId,
                'count' => count($nodeReports),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 检测客户端平台
     */
    private static function detectPlatform($userAgent)
    {
        $userAgent = strtolower($userAgent);

        if (strpos($userAgent, 'iphone') !== false || strpos($userAgent, 'ipad') !== false) {
            return 'ios';
        } elseif (strpos($userAgent, 'android') !== false) {
            return 'android';
        } elseif (strpos($userAgent, 'windows') !== false) {
            return 'windows';
        } elseif (strpos($userAgent, 'macintosh') !== false) {
            return 'macos';
        } elseif (strpos($userAgent, 'linux') !== false) {
            return 'linux';
        }

        return 'unknown';
    }
}