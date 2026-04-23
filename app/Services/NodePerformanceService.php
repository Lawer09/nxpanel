<?php

namespace App\Services;

use App\Models\NodePerformanceReport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodePerformanceService
{
    /**
     * Redis List key 前缀，按 5 分钟桶存储
     * 完整 key 格式: perf:raw:{YmdHi}  例如 perf:raw:202604191530
     */
    public const REDIS_RAW_PREFIX = 'perf:raw:';

    /**
     * 获取当前 5 分钟桶的 Redis key
     */
    public static function currentBucketKey(): string
    {
        $now = now();
        $minute = (int) floor($now->minute / 5) * 5;
        $bucket = $now->copy()->minute($minute)->second(0)->format('YmdHi');
        return self::REDIS_RAW_PREFIX . $bucket;
    }

    /**
     * 获取指定时间戳的桶 key
     */
    public static function bucketKeyAt(\Carbon\Carbon $time): string
    {
        $minute = (int) floor($time->minute / 5) * 5;
        $bucket = $time->copy()->minute($minute)->second(0)->format('YmdHi');
        return self::REDIS_RAW_PREFIX . $bucket;
    }

    /**
     * 单条上报 → 写入当前 5 分钟桶
     */
    public static function reportPerformance(int $userId, int $nodeId, array $data, string $clientIp, $request): void
    {
        $metadata = $data['metadata'] ?? [];

        $data = [
            'metadata' => $metadata,
            'reports' => [[
                'node_id' => (int) $nodeId,
                'delay' => (int) ($data['delay'] ?? 0),
                'success_rate' => (int) ($data['success_rate'] ?? 0),
            ]],
            'userId' => $userId,
            'clientIp' => $clientIp,
            'reported_at' => $metadata['timestamp'] ?? now()->getTimestampMs(),
            'created_at' => now()->toDateTimeString(),
        ];

        $key = self::currentBucketKey();
        Redis::rpush($key, json_encode($data, JSON_UNESCAPED_UNICODE));
        // 桶 key 设置 30 分钟过期，防止残留
        Redis::expire($key, 1800);
    }

    /**
     * 批量上报 → 写入当前 5 分钟桶（pipeline）
     */
    public static function batchReportPerformance(int $userId, array $nodeReports, array $metadata, string $clientIp, $request): void
    {
        $now = now()->toDateTimeString();
        $data = [
            'metadata' => $metadata,
            'reports' => $nodeReports,
            'userId' => $userId,
            'clientIp' => $clientIp,
            'reported_at' => $metadata['timestamp'] ?? now()->getTimestampMs(),
            'created_at' => $now,
        ];

        $key = self::currentBucketKey();
        
        Redis::pipeline(function ($pipe) use ($key, $data) {
            $pipe->rpush($key, json_encode($data, JSON_UNESCAPED_UNICODE));
            $pipe->expire($key, 1800);
        });

        Log::info('Batch performance buffered to Redis', [
            'user_id' => $userId,
            'node_count' => count($nodeReports),
            'bucket'  => $key,
        ]);
    }

    /**
     * 弹出指定桶的全部原始上报记录，弹完后删除 key
     */
    public static function popBucket(string $bucketKey, int $batchSize = 10000): array
    {
        $jsonArray = Redis::lrange($bucketKey, 0, $batchSize - 1);
        if (empty($jsonArray)) {
            return [];
        }
        // 消费完毕，删除桶
        Redis::del($bucketKey);

        return array_map(fn($json) => json_decode($json, true), $jsonArray);
    }
}