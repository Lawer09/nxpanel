<?php

namespace App\Services;

use App\Models\IpAllowlistRule;
use App\Models\ProjectUserAppMap;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

class IpAllowlistRuleService
{
    public function __construct(
        private readonly AllowedUserIpService $allowedUserIpService,
        private readonly BlockedUserIpService $blockedUserIpService
    ) {
    }

    /**
     * Paginate IP allowlist rules for admin management.
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $current = (int) ($filters['current'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 10);

        $query = IpAllowlistRule::query()
            ->with(['createdBy:id,email', 'updatedBy:id,email'])
            ->orderByDesc('id');

        if (array_key_exists('enabled', $filters) && $filters['enabled'] !== null) {
            $query->where('enabled', $this->normalizeBoolean($filters['enabled']));
        }

        $rules = $query->get();
        $country = $this->normalizeCountry($filters['country'] ?? null);
        $projectCode = $this->normalizeString($filters['projectCode'] ?? null);
        $packageName = $this->normalizePackageName($filters['packageName'] ?? null);

        if ($country !== null) {
            $rules = $rules->filter(
                fn(IpAllowlistRule $rule): bool => in_array($country, (array) ($rule->countries ?? []), true)
            )->values();
        }

        if ($projectCode !== null) {
            $rules = $rules->filter(
                fn(IpAllowlistRule $rule): bool => in_array($projectCode, (array) ($rule->project_codes ?? []), true)
            )->values();
        }

        if ($packageName !== null) {
            $rules = $rules->filter(
                fn(IpAllowlistRule $rule): bool => in_array($packageName, (array) ($rule->package_names ?? []), true)
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
     * Create an IP allowlist rule.
     */
    public function create(array $data, ?int $operatorUserId = null): IpAllowlistRule
    {
        $payload = $this->normalizePayload($data);
        $this->assertPayloadHasCondition($payload);

        return IpAllowlistRule::query()->create(array_merge(
            ['enabled' => true],
            $payload,
            [
                'created_by' => $operatorUserId,
                'updated_by' => $operatorUserId,
            ]
        ));
    }

    /**
     * Update an IP allowlist rule.
     */
    public function update(int $id, array $data, ?int $operatorUserId = null): IpAllowlistRule
    {
        $rule = IpAllowlistRule::query()->findOrFail($id);
        $rule->fill(array_merge($this->normalizePayload($data), [
            'updated_by' => $operatorUserId,
        ]));

        if (!$this->ruleHasAnyCondition($rule)) {
            throw new \InvalidArgumentException('At least one of countries, projectCodes, or packageNames is required.');
        }

        $rule->save();

        return $rule->refresh();
    }

    /**
     * Delete an IP allowlist rule.
     */
    public function delete(int $id): bool
    {
        $rule = IpAllowlistRule::query()->find($id);
        if (!$rule) {
            return false;
        }

        return (bool) $rule->delete();
    }

    /**
     * Automatically allow an IP when an enabled allowlist rule matches login metadata.
     */
    public function autoAllowIfRuleMatched(?string $ip, array $metadata): ?IpAllowlistRule
    {
        $ip = $this->blockedUserIpService->normalizeIp($ip);
        if ($ip === null) {
            return null;
        }

        $country = $this->normalizeCountry($metadata['country'] ?? null);
        $packageName = $this->resolvePackageName($metadata);
        $projectCodes = $this->resolveProjectCodesByPackageName($packageName);

        $rules = IpAllowlistRule::query()
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (!$this->ruleHasAnyCondition($rule)) {
                continue;
            }

            if (!$this->matchesCountry($rule, $country)) {
                continue;
            }

            if (!$this->matchesPackageName($rule, $packageName)) {
                continue;
            }

            if (!$this->matchesProjectCode($rule, $projectCodes)) {
                continue;
            }

            $this->allowedUserIpService->saveIps([$ip], null, $rule->reason, [
                'source' => 'ip_allowlist_rule',
                'rule_id' => (int) $rule->id,
                'rule_name' => $rule->name,
                'package_name' => $packageName,
                'project_codes' => $projectCodes,
                'country' => $country,
            ]);

            return $rule;
        }

        return null;
    }

    /**
     * Convert a rule model into the admin API response shape.
     */
    public function transform(IpAllowlistRule $rule): array
    {
        return [
            'id' => (int) $rule->id,
            'name' => $rule->name,
            'enabled' => (bool) $rule->enabled,
            'countries' => $rule->countries ?? [],
            'projectCodes' => $rule->project_codes ?? [],
            'packageNames' => $rule->package_names ?? [],
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

    private function normalizePayload(array $data): array
    {
        $payload = [];

        if (array_key_exists('name', $data)) {
            $payload['name'] = trim((string) $data['name']);
        }

        if (array_key_exists('enabled', $data)) {
            $payload['enabled'] = $this->normalizeBoolean($data['enabled']);
        }

        if (array_key_exists('countries', $data)) {
            $payload['countries'] = $this->normalizeStringList($data['countries'] ?? [], true);
        }

        if (array_key_exists('projectCodes', $data)) {
            $payload['project_codes'] = $this->normalizeStringList($data['projectCodes'] ?? [], false);
        }

        if (array_key_exists('packageNames', $data)) {
            $payload['package_names'] = $this->normalizeStringList($data['packageNames'] ?? [], false);
        }

        if (array_key_exists('reason', $data)) {
            $payload['reason'] = $data['reason'] === null ? null : trim((string) $data['reason']);
        }

        return $payload;
    }

    private function ruleHasAnyCondition(IpAllowlistRule $rule): bool
    {
        return !empty($rule->countries)
            || !empty($rule->project_codes)
            || !empty($rule->package_names);
    }

    private function assertPayloadHasCondition(array $payload): void
    {
        if (
            empty($payload['countries'])
            && empty($payload['project_codes'])
            && empty($payload['package_names'])
        ) {
            throw new \InvalidArgumentException('At least one of countries, projectCodes, or packageNames is required.');
        }
    }

    private function matchesCountry(IpAllowlistRule $rule, ?string $country): bool
    {
        $countries = (array) ($rule->countries ?? []);
        if (empty($countries)) {
            return true;
        }

        return $country !== null && in_array($country, $countries, true);
    }

    private function matchesPackageName(IpAllowlistRule $rule, ?string $packageName): bool
    {
        $packageNames = (array) ($rule->package_names ?? []);
        if (empty($packageNames)) {
            return true;
        }

        return $packageName !== null && in_array($packageName, $packageNames, true);
    }

    private function matchesProjectCode(IpAllowlistRule $rule, array $projectCodes): bool
    {
        $ruleProjectCodes = (array) ($rule->project_codes ?? []);
        if (empty($ruleProjectCodes)) {
            return true;
        }

        return !empty(array_intersect($projectCodes, $ruleProjectCodes));
    }

    private function resolvePackageName(array $metadata): ?string
    {
        $value = $metadata['package_name']
            ?? $metadata['packageName']
            ?? $metadata['app_id']
            ?? null;

        return $this->normalizePackageName($value);
    }

    private function resolveProjectCodesByPackageName(?string $packageName): array
    {
        if ($packageName === null) {
            return [];
        }

        return ProjectUserAppMap::query()
            ->where('app_id', $packageName)
            ->where('enabled', 1)
            ->pluck('project_code')
            ->filter(fn($code) => is_string($code) || is_numeric($code))
            ->map(fn($code) => trim((string) $code))
            ->filter(fn(string $code) => $code !== '')
            ->unique()
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

    private function normalizeCountry(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizePackageName(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
