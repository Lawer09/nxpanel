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
     * Check whether a client IP is marked as dangerous.
     */
    public function isDangerous(?string $ip): bool
    {
        $ip = $this->normalizeIp($ip);
        if ($ip === null) {
            return false;
        }

        return BlockedUserIp::query()
            ->where('ip', $ip)
            ->where('type', BlockedUserIp::TYPE_DANGEROUS)
            ->exists();
    }

    /**
     * Block the registration IPs recorded on the given users.
     */
    public function blockIpsForUsers(
        Collection $users,
        ?int $operatorUserId = null,
        ?string $reason = null,
        string $type = BlockedUserIp::TYPE_NORMAL
    ): array
    {
        return $this->blockIpsForUsersWithMetadata($users, $operatorUserId, $reason, [
            'source' => 'admin_batch_ban',
        ], $type);
    }

    /**
     * Ban users, clear their sessions, and block their registration IPs.
     */
    public function banUsersAndBlockIps(
        Collection $users,
        ?int $operatorUserId = null,
        ?string $reason = null,
        array $metadata = [],
        string $type = BlockedUserIp::TYPE_NORMAL
    ): array
    {
        $type = $this->normalizeType($type);

        return DB::transaction(function () use ($users, $operatorUserId, $reason, $metadata, $type): array {
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

            $ipResult = $this->blockIpsForUsersWithMetadata($lockedUsers, $operatorUserId, $reason, $metadata, $type);

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
    public function blockIpsForUsersWithMetadata(
        Collection $users,
        ?int $operatorUserId = null,
        ?string $reason = null,
        array $metadata = [],
        string $type = BlockedUserIp::TYPE_NORMAL
    ): array
    {
        $type = $this->normalizeType($type);
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
                    'type' => $type,
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

        if (!empty($filters['type'])) {
            $query->where('type', $this->normalizeType((string) $filters['type']));
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

    /**
     * Batch delete blocked registration IP records by ids.
     */
    public function batchDeleteByIds(array $ids): array
    {
        $ids = collect($ids)
            ->map(fn($id) => (int) $id)
            ->filter(fn(int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [
                'deletedCount' => 0,
                'requestedCount' => 0,
                'missingIds' => [],
            ];
        }

        return DB::transaction(function () use ($ids): array {
            $existingIds = BlockedUserIp::query()
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values();

            $deletedCount = 0;
            if ($existingIds->isNotEmpty()) {
                $deletedCount = BlockedUserIp::query()
                    ->whereIn('id', $existingIds->all())
                    ->delete();
            }

            return [
                'deletedCount' => (int) $deletedCount,
                'requestedCount' => $ids->count(),
                'missingIds' => $ids->diff($existingIds)->values()->all(),
            ];
        });
    }

    /**
     * Batch block explicit IP addresses and optionally ban users registered from them.
     */
    public function batchBlockIps(
        array $ips,
        ?int $operatorUserId = null,
        ?string $reason = null,
        string $type = BlockedUserIp::TYPE_NORMAL,
        bool $banUsers = false,
        array $metadata = []
    ): array {
        $type = $this->normalizeType($type);
        $normalizedIps = collect($ips)
            ->map(fn($ip) => $this->normalizeIp($ip))
            ->filter()
            ->unique()
            ->values();

        if ($normalizedIps->isEmpty()) {
            return [
                'requestedCount' => 0,
                'blockedIpCount' => 0,
                'blockedIps' => [],
                'bannedUserCount' => 0,
                'bannedUserIds' => [],
            ];
        }

        return DB::transaction(function () use ($normalizedIps, $operatorUserId, $reason, $type, $banUsers, $metadata): array {
            $usersByIp = collect();
            if ($banUsers) {
                $usersByIp = User::query()
                    ->where(function ($query) use ($normalizedIps): void {
                        foreach ($normalizedIps as $ip) {
                            $query->orWhere('register_metadata->ip', $ip);
                        }
                    })
                    ->lockForUpdate()
                    ->get()
                    ->groupBy(fn(User $user): string => (string) $this->extractRegisterIp($user));

                foreach ($usersByIp->flatten(1) as $user) {
                    if (!$user instanceof User) {
                        continue;
                    }

                    $user->banned = 1;
                    $user->save();
                    (new AuthService($user))->removeAllSessions();
                }
            }

            foreach ($normalizedIps as $ip) {
                $matchedUsers = $usersByIp->get($ip, collect());
                $firstUser = $matchedUsers->first();

                BlockedUserIp::query()->updateOrCreate(
                    ['ip' => $ip],
                    [
                        'type' => $type,
                        'banned_user_id' => $firstUser instanceof User ? $firstUser->id : null,
                        'operator_user_id' => $operatorUserId,
                        'reason' => $reason,
                        'metadata' => array_merge($metadata, [
                            'matched_user_ids' => $matchedUsers
                                ->filter(fn($user) => $user instanceof User)
                                ->pluck('id')
                                ->map(fn($id) => (int) $id)
                                ->values()
                                ->all(),
                        ]),
                    ]
                );
            }

            $bannedUserIds = $usersByIp
                ->flatten(1)
                ->filter(fn($user) => $user instanceof User)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            return [
                'requestedCount' => $normalizedIps->count(),
                'blockedIpCount' => $normalizedIps->count(),
                'blockedIps' => $normalizedIps->all(),
                'bannedUserCount' => count($bannedUserIds),
                'bannedUserIds' => $bannedUserIds,
            ];
        });
    }

    /**
     * Update a blocked registration IP record type by id.
     */
    public function updateTypeById(int $id, string $type): ?BlockedUserIp
    {
        $type = $this->normalizeType($type);

        return DB::transaction(function () use ($id, $type): ?BlockedUserIp {
            $record = BlockedUserIp::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$record) {
                return null;
            }

            $record->type = $type;
            $record->save();

            return $record->refresh();
        });
    }

    public function extractRegisterIp(User $user): ?string
    {
        $metadata = is_array($user->register_metadata) ? $user->register_metadata : [];

        return $this->normalizeIp($metadata['ip'] ?? null);
    }

    /**
     * Unban an invited user when neither side has a dangerous registration IP.
     */
    public function unbanUserIfInviteTrusted(User $user, User $inviter): bool
    {
        if (!$user->banned) {
            return false;
        }

        if ($this->isDangerous($this->extractRegisterIp($user))) {
            return false;
        }

        if ($this->isDangerous($this->extractRegisterIp($inviter))) {
            return false;
        }

        $user->banned = 0;

        return (bool) $user->save();
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

    public function normalizeType(?string $type): string
    {
        $type = strtolower(trim((string) $type));

        return in_array($type, [BlockedUserIp::TYPE_NORMAL, BlockedUserIp::TYPE_DANGEROUS], true)
            ? $type
            : BlockedUserIp::TYPE_NORMAL;
    }
}
