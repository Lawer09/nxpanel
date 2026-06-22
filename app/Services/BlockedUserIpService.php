<?php

namespace App\Services;

use App\Models\BlockedUserIp;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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
        return $this->blockIpsForUsersWithMetadata($users, $operatorUserId, $reason, [
            'source' => 'admin_batch_ban',
        ]);
    }

    /**
     * Ban users, clear their sessions, and block their registration IPs.
     */
    public function banUsersAndBlockIps(Collection $users, ?int $operatorUserId = null, ?string $reason = null, array $metadata = []): array
    {
        return DB::transaction(function () use ($users, $operatorUserId, $reason, $metadata): array {
            $ids = $users->filter(fn($user) => $user instanceof User)
                ->pluck('id')
                ->filter()
                ->values();

            $lockedUsers = User::query()
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->get();

            foreach ($lockedUsers as $user) {
                $user->banned = 1;
                $user->save();
                (new AuthService($user))->removeAllSessions();
            }

            $ipResult = $this->blockIpsForUsersWithMetadata($lockedUsers, $operatorUserId, $reason, $metadata);

            return [
                'bannedUserCount' => $lockedUsers->count(),
                'blockedIpCount' => count($ipResult['blocked_ips']),
                'blockedIps' => $ipResult['blocked_ips'],
                'skippedIpUserIds' => $ipResult['skipped_user_ids'],
            ];
        });
    }

    /**
     * Ban users and clear sessions without writing IP block records.
     */
    public function banUsers(Collection $users): int
    {
        return DB::transaction(function () use ($users): int {
            $ids = $users->filter(fn($user) => $user instanceof User)
                ->pluck('id')
                ->filter()
                ->values();

            $lockedUsers = User::query()
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->get();

            foreach ($lockedUsers as $user) {
                $user->banned = 1;
                $user->save();
                (new AuthService($user))->removeAllSessions();
            }

            return $lockedUsers->count();
        });
    }

    /**
     * Block registration IPs with a caller-provided metadata payload.
     */
    public function blockIpsForUsersWithMetadata(Collection $users, ?int $operatorUserId = null, ?string $reason = null, array $metadata = []): array
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
                    'metadata' => array_merge($metadata, [
                        'user_email' => $user->email,
                    ]),
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
