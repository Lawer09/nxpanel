<?php

namespace App\Services;

use App\Models\AidChannelTypeUpdateQueue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AidChannelTypeUpdateQueueService
{
    /**
     * Queue an existing AID user's channel_type update only when current value is UNKNOWN.
     */
    public function enqueueIfNeeded(User $user, mixed $newChannelType, int $loginAt): bool
    {
        if ($this->normalizeChannelType($this->currentChannelType($user)) !== 'UNKNOWN') {
            return false;
        }

        $normalizedNewChannelType = $this->normalizeChannelType($newChannelType);
        if ($normalizedNewChannelType === null || $normalizedNewChannelType === 'UNKNOWN') {
            return false;
        }

        $queue = AidChannelTypeUpdateQueue::query()->firstOrNew([
            'user_id' => (int) $user->id,
        ]);
        $queue->channel_type = $normalizedNewChannelType;
        $queue->last_login_at = $loginAt;
        $queue->error_message = null;
        if (!$queue->exists) {
            $queue->attempts = 0;
        }
        $queue->save();

        return true;
    }

    /**
     * Flush queued channel_type updates into v2_user.register_metadata.
     */
    public function flush(int $limit = 1000): array
    {
        $limit = max(1, $limit);
        $queues = AidChannelTypeUpdateQueue::query()
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $stats = [
            'scanned' => $queues->count(),
            'updated' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        foreach ($queues as $queue) {
            try {
                DB::transaction(function () use ($queue): void {
                    $lockedQueue = AidChannelTypeUpdateQueue::query()
                        ->whereKey($queue->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$lockedQueue) {
                        return;
                    }

                    $user = User::query()
                        ->whereKey($lockedQueue->user_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$user) {
                        throw new \RuntimeException('user_not_found');
                    }

                    $metadata = is_array($user->register_metadata) ? $user->register_metadata : [];
                    $metadata['channel_type'] = $lockedQueue->channel_type;
                    $user->register_metadata = $metadata;
                    $user->last_login_at = (int) $lockedQueue->last_login_at;
                    $user->save();

                    $lockedQueue->delete();
                });

                $stats['updated']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['failures'][] = [
                    'queue_id' => (int) $queue->id,
                    'user_id' => (int) $queue->user_id,
                    'error' => $e->getMessage(),
                ];

                AidChannelTypeUpdateQueue::query()
                    ->whereKey($queue->id)
                    ->increment('attempts', 1, [
                        'error_message' => mb_substr($e->getMessage(), 0, 500),
                    ]);
            }
        }

        return $stats;
    }

    private function currentChannelType(User $user): mixed
    {
        $metadata = is_array($user->register_metadata) ? $user->register_metadata : [];

        return $metadata['channel_type'] ?? null;
    }

    public function normalizeChannelType(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
