<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Redis;

class UserReportService
{
    public const REDIS_RAW_PREFIX = 'user_report:raw:';

    public static function enabled(): bool
    {
        return (bool) env('USER_REPORT_ENABLED', true);
    }

    public static function pushRawPayload(array $payload, int $reportAtMs): void
    {
        $key = self::bucketKeyByTimestampMs($reportAtMs);

        Redis::pipeline(function ($pipe) use ($key, $payload) {
            $pipe->rpush($key, json_encode($payload, JSON_UNESCAPED_UNICODE));
            $pipe->expire($key, 3600);
        });
    }

    public static function bucketKeyByTimestampMs(int $timestampMs): string
    {
        $time = Carbon::createFromTimestampMsUTC($timestampMs)->setTimezone('Asia/Shanghai');
        return self::bucketKeyAtUtc8($time);
    }

    public static function bucketKeyAtUtc8(Carbon $time): string
    {
        $bucketMinute = (int) floor(((int) $time->minute) / 5) * 5;
        $bucket = $time->copy()->second(0)->minute($bucketMinute)->format('YmdHi');

        return self::REDIS_RAW_PREFIX . $bucket;
    }

    public static function popBucket(string $bucketKey, int $batchSize = 10000): array
    {
        $jsonArray = Redis::lrange($bucketKey, 0, $batchSize - 1);
        if (empty($jsonArray)) {
            return [];
        }

        Redis::del($bucketKey);

        return array_values(array_filter(array_map(function ($json) {
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : null;
        }, $jsonArray)));
    }

    public static function readBucket(string $bucketKey, int $batchSize = 10000): array
    {
        $jsonArray = Redis::lrange($bucketKey, 0, $batchSize - 1);
        if (empty($jsonArray)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($json) {
            $decoded = json_decode($json, true);
            return is_array($decoded) ? $decoded : null;
        }, $jsonArray)));
    }

    public static function deleteBucket(string $bucketKey): void
    {
        Redis::del($bucketKey);
    }

    public static function restoreBucket(string $bucketKey, array $payloads): void
    {
        if (empty($payloads)) {
            return;
        }

        Redis::pipeline(function ($pipe) use ($bucketKey, $payloads) {
            foreach ($payloads as $payload) {
                $pipe->rpush($bucketKey, json_encode($payload, JSON_UNESCAPED_UNICODE));
            }
            $pipe->expire($bucketKey, 3600);
        });
    }

    public static function normalizeTimestampMs($timestamp): ?int
    {
        if ($timestamp === null || $timestamp === '') {
            return null;
        }

        if (!is_numeric($timestamp)) {
            return null;
        }

        $value = (int) $timestamp;
        if ($value <= 0) {
            return null;
        }

        return $value < 1000000000000 ? $value * 1000 : $value;
    }

    public static function resolveReportAtMs(array $metadata): int
    {
        $timestampMs = self::normalizeTimestampMs($metadata['timestamp'] ?? null);
        $nowMs = now()->getTimestampMs();

        if ($timestampMs === null || $timestampMs > $nowMs) {
            return $nowMs;
        }

        return $timestampMs;
    }
}
