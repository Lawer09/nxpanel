<?php

namespace App\Services;

use App\Models\NodePerformanceReport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodePerformanceService
{
    /**
     * Redis List key，存放原始上报数据
     */
    public const REDIS_RAW_KEY = 'perf:raw_reports';


    /**
     * 单条上报 → 写入 Redis
     */
    public static function reportPerformance(int $userId, int $nodeId, array $data, string $clientIp, $request): void
    {
        $metadata = $data['metadata'] ?? [];

        $record = [
            'user_id'         => $userId,
            'node_id'         => $nodeId,
            'delay'           => (int) ($data['delay'] ?? 0),
            'success_rate'    => (int) ($data['success_rate'] ?? 0),
            'client_ip'       => $clientIp,
            'client_country'  => $metadata['country'] ?? null,
            'client_city'     => $metadata['city'] ?? null,
            'client_isp'      => $metadata['isp'] ?? null,
            'platform'        => $metadata['platform'] ?? null,
            'brand'           => $metadata['brand'] ?? null,
            'app_id'          => $metadata['app_id'] ?? null,
            'app_version'     => $metadata['app_version'] ?? null,
            'connect_country' => $metadata['connect_country'] ?? null,
            'reported_at'     => $metadata['timestamp'] ?? now()->getTimestampMs(),
            'created_at'      => now()->toDateTimeString(),
        ];

        Redis::rpush(self::REDIS_RAW_KEY, json_encode($record, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 批量上报 → 写入 Redis（pipeline）
     */
    public static function batchReportPerformance(int $userId, array $nodeReports, string $clientIp, $request): void
    {
        $records = [];
        $now = now()->toDateTimeString();

        foreach ($nodeReports as $report) {
            $metadata = $report['metadata'] ?? [];

            $record = [
                'user_id'         => $userId,
                'node_id'         => (int) $report['node_id'],
                'delay'           => (int) ($report['delay'] ?? 0),
                'success_rate'    => (int) ($report['success_rate'] ?? 0),
                'client_ip'       => $clientIp,
                'client_country'  => $metadata['country'] ?? null,
                'client_city'     => $metadata['city'] ?? null,
                'client_isp'      => $metadata['isp'] ?? null,
                'platform'        => $metadata['platform'] ?? null,
                'brand'           => $metadata['brand'] ?? null,
                'app_id'          => $metadata['app_id'] ?? null,
                'app_version'     => $metadata['app_version'] ?? null,
                'connect_country' => $metadata['connect_country'] ?? null,
                'reported_at'     => $metadata['timestamp'] ?? now()->getTimestampMs(),
                'created_at'      => $now,
            ];

            $records[] = $record;
        }

        // Pipeline 批量写入
        Redis::pipeline(function ($pipe) use ($records) {
            foreach ($records as $record) {
                $pipe->rpush(self::REDIS_RAW_KEY, json_encode($record, JSON_UNESCAPED_UNICODE));
            }
        });

        Log::info('Batch performance buffered to Redis', [
            'user_id' => $userId,
            'count'   => count($records),
        ]);
    }

    /**
     * 从 Redis 弹出指定数量的原始上报记录
     *
     * @param int $batchSize 每次弹出数量
     * @return array
     */
    public static function popRawReports(int $batchSize = 5000): array
    {
        $jsonArray = Redis::lpop(self::REDIS_RAW_KEY, $batchSize);
        if (empty($jsonArray)) {
            return [];
        }

        return array_map(fn($json) => json_decode($json, true), $jsonArray);
    }

}