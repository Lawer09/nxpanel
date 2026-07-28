<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class AidLoginActivityService
{
    private const REDIS_KEY = 'aid_login:last_login_at';
    private const REDIS_TTL_SECONDS = 604800;

    /**
     * Record the latest AID login timestamp for asynchronous database flushing.
     */
    public function record(int $userId, int $loginAt): bool
    {
        if ($userId <= 0 || $loginAt <= 0) {
            return false;
        }

        if (app()->environment('testing') && config('cache.default') === 'array') {
            return true;
        }

        try {
            Redis::zadd(self::REDIS_KEY, $loginAt, (string) $userId);
            Redis::expire(self::REDIS_KEY, self::REDIS_TTL_SECONDS);
            return true;
        } catch (\Throwable $e) {
            Log::warning('AID login activity record failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Flush aggregated AID login timestamps from Redis to v2_user.last_login_at.
     */
    public function flush(int $limit = 5000): array
    {
        $limit = max(1, $limit);
        $items = $this->popPendingItems($limit);
        $stats = [
            'scanned' => count($items),
            'updated' => 0,
            'missing' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        foreach ($items as $userId => $loginAt) {
            try {
                $affected = User::query()
                    ->whereKey($userId)
                    ->where(function ($query) use ($loginAt): void {
                        $query->whereNull('last_login_at')
                            ->orWhere('last_login_at', '<', $loginAt);
                    })
                    ->update(['last_login_at' => $loginAt]);

                if ($affected > 0) {
                    $stats['updated']++;
                    continue;
                }

                if (!User::query()->whereKey($userId)->exists()) {
                    $stats['missing']++;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['failures'][] = [
                    'user_id' => $userId,
                    'last_login_at' => $loginAt,
                    'error' => $e->getMessage(),
                ];
                $this->requeue($userId, $loginAt);
            }
        }

        return $stats;
    }

    private function popPendingItems(int $limit): array
    {
        try {
            return $this->normalizePoppedItems(Redis::zpopmin(self::REDIS_KEY, $limit));
        } catch (\Throwable $e) {
            Log::warning('AID login activity pop failed', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function requeue(int $userId, int $loginAt): void
    {
        try {
            Redis::zadd(self::REDIS_KEY, $loginAt, (string) $userId);
            Redis::expire(self::REDIS_KEY, self::REDIS_TTL_SECONDS);
        } catch (\Throwable $e) {
            Log::error('AID login activity requeue failed', [
                'user_id' => $userId,
                'last_login_at' => $loginAt,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizePoppedItems(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $items = [];
        if (array_is_list($raw)) {
            if (isset($raw[0]) && is_array($raw[0])) {
                foreach ($raw as $entry) {
                    $score = $entry['score'] ?? ($entry[1] ?? null);
                    $member = $entry['value'] ?? ($entry['member'] ?? ($entry[0] ?? null));
                    $this->appendNormalizedItem($items, $member, $score);
                }

                return $items;
            }

            for ($i = 0; $i < count($raw); $i += 2) {
                $member = $raw[$i] ?? null;
                $score = $raw[$i + 1] ?? null;
                $this->appendNormalizedItem($items, $member, $score);
            }

            return $items;
        }

        foreach ($raw as $member => $score) {
            $this->appendNormalizedItem($items, $member, $score);
        }

        return $items;
    }

    private function appendNormalizedItem(array &$items, mixed $member, mixed $score): void
    {
        if (!is_numeric($member) || !is_numeric($score)) {
            return;
        }

        $userId = (int) $member;
        $loginAt = (int) $score;
        if ($userId <= 0 || $loginAt <= 0) {
            return;
        }

        $items[$userId] = max($items[$userId] ?? 0, $loginAt);
    }
}
