<?php

namespace App\Services;

use App\Models\BlockedUserIp;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class BlockedUserIpService
{
    /**
     * Check whether a client IP is currently blocked.
     */
    public function isBlocked(?string $ip): bool
    {
        $ip = $this->normalizeIp($ip);
        if ($ip === null) {
            return false;
        }

        return BlockedUserIp::query()->where('ip', $ip)->exists();
    }

    /**
     * Block the registration IPs recorded on the given users.
     */
    public function blockIpsForUsers(Collection $users, ?int $operatorUserId = null, ?string $reason = null): array
    {
        $blockedIps = [];
        $skippedUsers = [];

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $ip = $this->extractRegisterIp($user);
            if ($ip === null) {
                $skippedUsers[] = (int) $user->id;
                continue;
            }

            BlockedUserIp::query()->updateOrCreate(
                ['ip' => $ip],
                [
                    'banned_user_id' => $user->id,
                    'operator_user_id' => $operatorUserId,
                    'reason' => $reason,
                    'metadata' => [
                        'source' => 'admin_batch_ban',
                        'user_email' => $user->email,
                    ],
                ]
            );

            $blockedIps[] = $ip;
        }

        return [
            'blocked_ips' => array_values(array_unique($blockedIps)),
            'skipped_user_ids' => $skippedUsers,
        ];
    }

    /**
     * Paginate blocked registration IP records for admin management.
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $current = (int) ($filters['current'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 10);

        $query = BlockedUserIp::query()
            ->with([
                'bannedUser:id,email',
                'operatorUser:id,email',
            ])
            ->orderByDesc('id');

        if (!empty($filters['ip'])) {
            $query->where('ip', $filters['ip']);
        }

        if (!empty($filters['bannedUserId'])) {
            $query->where('banned_user_id', (int) $filters['bannedUserId']);
        }

        if (!empty($filters['operatorUserId'])) {
            $query->where('operator_user_id', (int) $filters['operatorUserId']);
        }

        return $query->paginate($pageSize, ['*'], 'page', $current);
    }

    /**
     * Delete a blocked registration IP record by id.
     */
    public function deleteById(int $id): bool
    {
        $record = BlockedUserIp::query()->find($id);
        if (!$record) {
            return false;
        }

        return (bool) $record->delete();
    }

    public function extractRegisterIp(User $user): ?string
    {
        $metadata = is_array($user->register_metadata) ? $user->register_metadata : [];

        return $this->normalizeIp($metadata['ip'] ?? null);
    }

    public function normalizeIp(mixed $ip): ?string
    {
        if ($ip === null || is_array($ip) || is_object($ip)) {
            return null;
        }

        $normalized = trim((string) $ip);
        if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_IP)) {
            return null;
        }

        return $normalized;
    }
}
