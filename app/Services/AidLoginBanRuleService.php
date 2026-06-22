<?php

namespace App\Services;

use App\Models\AidLoginBanRule;
use App\Models\ProjectUserAppMap;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

class AidLoginBanRuleService
{
    public function __construct(
        private readonly BlockedUserIpService $blockedUserIpService
    ) {
    }

    /**
     * Paginate custom AID login ban rules for admin management.
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $current = (int) ($filters['current'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 10);

        $query = AidLoginBanRule::query()
            ->with([
                'createdBy:id,email',
                'updatedBy:id,email',
            ])
            ->orderByDesc('id');

        if (array_key_exists('enabled', $filters) && $filters['enabled'] !== null) {
            $query->where('enabled', (bool) $filters['enabled']);
        }

        $rules = $query->get();
        $packageName = $this->normalizePackageName($filters['packageName'] ?? null);
        $country = $this->normalizeCountry($filters['country'] ?? null);

        if ($packageName !== null) {
            $rules = $rules->filter(
                fn(AidLoginBanRule $rule): bool => empty($rule->package_names)
                    || in_array($packageName, (array) ($rule->package_names ?? []), true)
            )->values();
        }

        if ($country !== null) {
            $rules = $rules->filter(
                fn(AidLoginBanRule $rule): bool => empty($rule->countries)
                    || in_array($country, (array) ($rule->countries ?? []), true)
            )->values();
        }

        return new Paginator(
            $rules->forPage($current, $pageSize)->values(),
            $rules->count(),
            $pageSize,
            $current,
            ['path' => request()->url()]
        );
    }

    /**
     * Create a custom AID login ban rule.
     */
    public function create(array $data, ?int $operatorUserId = null): AidLoginBanRule
    {
        return AidLoginBanRule::query()->create(array_merge(
            ['enabled' => true],
            $this->normalizePayload($data),
            [
                'created_by' => $operatorUserId,
                'updated_by' => $operatorUserId,
            ]
        ));
    }

    /**
     * Update a custom AID login ban rule.
     */
    public function update(int $id, array $data, ?int $operatorUserId = null): AidLoginBanRule
    {
        $rule = AidLoginBanRule::query()->findOrFail($id);
        $rule->fill(array_merge($this->normalizePayload($data, $rule), [
            'updated_by' => $operatorUserId,
        ]));
        $rule->save();

        return $rule->refresh();
    }

    /**
     * Delete a custom AID login ban rule.
     */
    public function delete(int $id): bool
    {
        $rule = AidLoginBanRule::query()->find($id);
        if (!$rule) {
            return false;
        }

        return (bool) $rule->delete();
    }

    /**
     * Ban a newly created AID user when any enabled custom rule matches.
     */
    public function banIfMatched(User $user, string $aid, array $metadata): ?AidLoginBanRule
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $packageName = $this->resolvePackageName($metadata);
        $country = $this->normalizeCountry($metadata['country'] ?? null);

        $rules = AidLoginBanRule::query()
            ->where('enabled', true)
            ->where(function ($query) use ($now): void {
                $query->whereNull('cutoff_at')
                    ->orWhere('cutoff_at', '>=', $now->timestamp);
            })
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (!$this->matchesWeeklyWindow($rule, $now)) {
                continue;
            }

            if (!$this->matchesPackageName($rule, $packageName)) {
                continue;
            }

            if (!$this->matchesCountry($rule, $country)) {
                continue;
            }

            $this->blockedUserIpService->banUsersAndBlockIps(collect([$user]), null, $rule->reason, [
                'source' => 'aid_login_ban_rule',
                'rule_id' => (int) $rule->id,
                'rule_name' => $rule->name,
                'package_name' => $packageName,
                'country' => $country,
                'aid' => $aid,
            ]);

            $user->refresh();
            NodeSyncService::notifyUsersUpdated();

            return $rule;
        }

        return null;
    }

    /**
     * Convert a rule model into the admin API response shape.
     */
    public function transform(AidLoginBanRule $rule): array
    {
        return [
            'id' => (int) $rule->id,
            'name' => $rule->name,
            'enabled' => (bool) $rule->enabled,
            'cutoffAt' => $this->formatTimestamp($rule->cutoff_at),
            'weeklyWindows' => $rule->weekly_windows ?? [],
            'packageNames' => $rule->package_names ?? [],
            'projectCodes' => $rule->project_codes ?? [],
            'countries' => $rule->countries ?? [],
            'reason' => $rule->reason,
            'createdBy' => $rule->createdBy ? [
                'id' => (int) $rule->createdBy->id,
                'email' => $rule->createdBy->email,
            ] : null,
            'updatedBy' => $rule->updatedBy ? [
                'id' => (int) $rule->updatedBy->id,
                'email' => $rule->updatedBy->email,
            ] : null,
            'createdAt' => $rule->created_at,
            'updatedAt' => $rule->updated_at,
        ];
    }

    private function normalizePayload(array $data, ?AidLoginBanRule $rule = null): array
    {
        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('enabled', $data)) {
            $payload['enabled'] = (bool) $data['enabled'];
        }

        if (array_key_exists('cutoffAt', $data)) {
            $payload['cutoff_at'] = $data['cutoffAt'] === null
                ? null
                : CarbonImmutable::parse((string) $data['cutoffAt'], config('app.timezone'))->timestamp;
        }

        if (array_key_exists('weeklyWindows', $data)) {
            $payload['weekly_windows'] = is_array($data['weeklyWindows'])
                ? $this->normalizeWeeklyWindows($data['weeklyWindows'])
                : null;
        }

        if (array_key_exists('projectCodes', $data)) {
            $payload['project_codes'] = $this->normalizeStringList($data['projectCodes'] ?? [], false);
        }

        if (array_key_exists('packageNames', $data) || array_key_exists('projectCodes', $data)) {
            if (array_key_exists('packageNames', $data)) {
                $packageNames = $this->normalizeStringList($data['packageNames'] ?? [], false);
            } elseif (array_key_exists('projectCodes', $data)) {
                $packageNames = $this->removeProjectResolvedPackageNames(
                    (array) ($rule?->package_names ?? []),
                    (array) ($rule?->project_codes ?? [])
                );
            } else {
                $packageNames = (array) ($rule?->package_names ?? []);
            }

            $projectCodes = array_key_exists('projectCodes', $data)
                ? ($payload['project_codes'] ?? [])
                : (array) ($rule?->project_codes ?? []);

            $payload['package_names'] = $this->mergePackageNamesWithProjectCodes($packageNames, $projectCodes);
        }

        if (array_key_exists('countries', $data)) {
            $payload['countries'] = $this->normalizeStringList($data['countries'] ?? [], true);
        }

        if (array_key_exists('reason', $data)) {
            $payload['reason'] = $data['reason'] === null ? null : trim((string) $data['reason']);
        }

        return $payload;
    }

    private function normalizeWeeklyWindows(array $windows): array
    {
        return collect($windows)
            ->filter(fn($window) => is_array($window))
            ->map(fn(array $window): array => [
                'weekday' => (int) $window['weekday'],
                'start' => (string) $window['start'],
                'end' => (string) $window['end'],
            ])
            ->values()
            ->all();
    }

    private function normalizeStringList(array $values, bool $upper): array
    {
        return collect($values)
            ->filter(fn($value) => is_string($value) || is_numeric($value))
            ->map(fn($value) => trim((string) $value))
            ->filter(fn(string $value) => $value !== '')
            ->map(fn(string $value) => $upper ? strtoupper($value) : $value)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Merge explicit matched package names with enabled app IDs resolved by project codes.
     */
    private function mergePackageNamesWithProjectCodes(array $packageNames, array $projectCodes): array
    {
        return $this->normalizeStringList(array_merge(
            $packageNames,
            $this->resolveAppIdsByProjectCodes($projectCodes)
        ), false);
    }

    /**
     * Keep manually configured package names when project code sources are replaced.
     */
    private function removeProjectResolvedPackageNames(array $packageNames, array $projectCodes): array
    {
        $resolvedPackageNames = $this->resolveAppIdsByProjectCodes($projectCodes, false);
        if (empty($resolvedPackageNames)) {
            return $this->normalizeStringList($packageNames, false);
        }

        return collect($this->normalizeStringList($packageNames, false))
            ->reject(fn(string $packageName): bool => in_array($packageName, $resolvedPackageNames, true))
            ->values()
            ->all();
    }

    /**
     * Resolve enabled project app mappings into app IDs used by login metadata matching.
     */
    private function resolveAppIdsByProjectCodes(array $projectCodes, bool $enabledOnly = true): array
    {
        $projectCodes = $this->normalizeStringList($projectCodes, false);
        if (empty($projectCodes)) {
            return [];
        }

        $query = ProjectUserAppMap::query()
            ->whereIn('project_code', $projectCodes)
            ->whereNotNull('app_id')
            ->where('app_id', '<>', '');

        if ($enabledOnly) {
            $query->where('enabled', 1);
        }

        return $query
            ->pluck('app_id')
            ->filter(fn($appId) => is_string($appId) || is_numeric($appId))
            ->map(fn($appId) => trim((string) $appId))
            ->filter(fn(string $appId) => $appId !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function matchesWeeklyWindow(AidLoginBanRule $rule, CarbonImmutable $now): bool
    {
        if (empty($rule->weekly_windows)) {
            return true;
        }

        $weekday = (int) $now->isoWeekday();
        $current = $now->format('H:i');

        foreach ((array) $rule->weekly_windows as $window) {
            if (!is_array($window)) {
                continue;
            }

            if ((int) ($window['weekday'] ?? 0) !== $weekday) {
                continue;
            }

            $start = (string) ($window['start'] ?? '');
            $end = (string) ($window['end'] ?? '');

            if ($start <= $current && $current <= $end) {
                return true;
            }
        }

        return false;
    }

    private function matchesPackageName(AidLoginBanRule $rule, ?string $packageName): bool
    {
        $packageNames = (array) ($rule->package_names ?? []);
        if (empty($packageNames)) {
            return true;
        }

        return $packageName !== null && in_array($packageName, $packageNames, true);
    }

    private function matchesCountry(AidLoginBanRule $rule, ?string $country): bool
    {
        $countries = (array) ($rule->countries ?? []);
        if (empty($countries)) {
            return true;
        }

        return $country !== null && in_array($country, $countries, true);
    }

    private function resolvePackageName(array $metadata): ?string
    {
        $value = $metadata['package_name']
            ?? $metadata['packageName']
            ?? $metadata['app_id']
            ?? null;

        return $this->normalizePackageName($value);
    }

    private function normalizePackageName(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeCountry(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function formatTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
        }

        return CarbonImmutable::createFromTimestamp((int) $value, config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }
}
