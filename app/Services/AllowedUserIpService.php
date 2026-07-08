<?php

namespace App\Services;

use App\Models\AllowedUserIp;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AllowedUserIpService
{
    public function __construct(
        private readonly BlockedUserIpService $blockedUserIpService
    ) {
    }

    /**
     * Check whether an IP is explicitly allowed.
     */
    public function isAllowed(?string $ip): bool
    {
        $ip = $this->blockedUserIpService->normalizeIp($ip);
        if ($ip === null) {
            return false;
        }

        return AllowedUserIp::query()->where('ip', $ip)->exists();
    }

    /**
     * Paginate allowlist IP records for admin management.
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $current = (int) ($filters['current'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 10);

        $query = AllowedUserIp::query()
            ->with('operatorUser:id,email')
            ->orderByDesc('id');

        if (!empty($filters['ip'])) {
            $query->where('ip', (string) $filters['ip']);
        }

        if (!empty($filters['operatorUserId'])) {
            $query->where('operator_user_id', (int) $filters['operatorUserId']);
        }

        return $query->paginate($pageSize, ['*'], 'page', $current);
    }

    /**
     * Add or update explicit allowlist IP records.
     */
    public function saveIps(array $ips, ?int $operatorUserId = null, ?string $reason = null, array $metadata = []): array
    {
        $normalizedIps = collect($ips)
            ->map(fn($ip) => $this->blockedUserIpService->normalizeIp($ip))
            ->filter()
            ->unique()
            ->values();

        if ($normalizedIps->isEmpty()) {
            return [
                'requestedCount' => 0,
                'allowedIpCount' => 0,
                'allowedIps' => [],
            ];
        }

        return DB::transaction(function () use ($normalizedIps, $operatorUserId, $reason, $metadata): array {
            foreach ($normalizedIps as $ip) {
                AllowedUserIp::query()->updateOrCreate(
                    ['ip' => $ip],
                    [
                        'operator_user_id' => $operatorUserId,
                        'reason' => $reason,
                        'metadata' => $metadata,
                    ]
                );
            }

            return [
                'requestedCount' => $normalizedIps->count(),
                'allowedIpCount' => $normalizedIps->count(),
                'allowedIps' => $normalizedIps->all(),
            ];
        });
    }

    /**
     * Delete an allowlist IP record by id.
     */
    public function deleteById(int $id): bool
    {
        $record = AllowedUserIp::query()->find($id);
        if (!$record) {
            return false;
        }

        return (bool) $record->delete();
    }

    /**
     * Batch delete allowlist IP records by ids.
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
            $existingIds = AllowedUserIp::query()
                ->whereIn('id', $ids->all())
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values();

            $deletedCount = 0;
            if ($existingIds->isNotEmpty()) {
                $deletedCount = AllowedUserIp::query()
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
     * Transform an allowlist IP record into admin API shape.
     */
    public function transform(AllowedUserIp $record): array
    {
        return [
            'id' => (int) $record->id,
            'ip' => (string) $record->ip,
            'reason' => $record->reason,
            'metadata' => $record->metadata,
            'operator_user_id' => $record->operator_user_id,
            'operator_user' => $record->operatorUser ? [
                'id' => (int) $record->operatorUser->id,
                'email' => $record->operatorUser->email,
            ] : null,
            'created_at' => $record->created_at,
            'updated_at' => $record->updated_at,
        ];
    }
}
