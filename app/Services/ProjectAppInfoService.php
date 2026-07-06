<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Project;
use App\Models\ProjectAppInfo;

class ProjectAppInfoService
{
    /**
     * Query project application information records with pagination.
     */
    public function index(array $filters): array
    {
        $query = ProjectAppInfo::query();

        $projectCode = $this->resolveProjectCode($filters, false);
        if ($projectCode !== null) {
            $query->where('project_code', $projectCode);
        }
        if (!empty($filters['appId'])) {
            $query->where('app_id', trim((string) $filters['appId']));
        }
        if (array_key_exists('enabled', $filters) && $filters['enabled'] !== null && $filters['enabled'] !== '') {
            $query->where('enabled', (int) $filters['enabled']);
        }
        if (!empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($query) use ($keyword): void {
                $query->where('project_code', 'like', "%{$keyword}%")
                    ->orWhere('app_id', 'like', "%{$keyword}%")
                    ->orWhere('app_name', 'like', "%{$keyword}%")
                    ->orWhere('platform', 'like', "%{$keyword}%");
            });
        }

        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 20);
        $total = $query->count();
        $items = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'data' => $items,
        ];
    }

    /**
     * Load one project application information record.
     */
    public function detail(int $id): ProjectAppInfo
    {
        $appInfo = ProjectAppInfo::find($id);
        if (!$appInfo) {
            throw new BusinessException([404, 'Project app info not found']);
        }

        return $appInfo;
    }

    /**
     * Create one project application information record.
     */
    public function store(array $data): ProjectAppInfo
    {
        $projectCode = $this->resolveProjectCode($data, true);
        $appId = trim((string) $data['appId']);
        if ($appId === '') {
            throw new BusinessException([422, 'appId cannot be empty']);
        }

        $exists = ProjectAppInfo::query()
            ->where('project_code', $projectCode)
            ->where('app_id', $appId)
            ->exists();
        if ($exists) {
            throw new BusinessException([422, 'Project app info already exists']);
        }

        return ProjectAppInfo::create(array_merge(
            ['project_code' => $projectCode, 'app_id' => $appId],
            $this->extractAttributes($data)
        ));
    }

    /**
     * Update one project application information record.
     */
    public function update(int $id, array $data): ProjectAppInfo
    {
        $appInfo = $this->detail($id);
        $attributes = $this->extractAttributes($data);

        if (array_key_exists('projectCode', $data) || array_key_exists('projectId', $data)) {
            $attributes['project_code'] = $this->resolveProjectCode($data, true);
        }
        if (array_key_exists('appId', $data)) {
            $appId = trim((string) $data['appId']);
            if ($appId === '') {
                throw new BusinessException([422, 'appId cannot be empty']);
            }
            $attributes['app_id'] = $appId;
        }

        if (isset($attributes['project_code']) || isset($attributes['app_id'])) {
            $projectCode = $attributes['project_code'] ?? $appInfo->project_code;
            $appId = $attributes['app_id'] ?? $appInfo->app_id;
            $exists = ProjectAppInfo::query()
                ->where('project_code', $projectCode)
                ->where('app_id', $appId)
                ->where('id', '!=', $appInfo->id)
                ->exists();
            if ($exists) {
                throw new BusinessException([422, 'Project app info already exists']);
            }
        }

        if (!empty($attributes)) {
            $appInfo->fill($attributes);
            if ($appInfo->isDirty()) {
                $appInfo->save();
            }
        }

        return $appInfo;
    }

    /**
     * Delete one project application information record.
     */
    public function destroy(int $id): void
    {
        $appInfo = $this->detail($id);
        $appInfo->delete();
    }

    /**
     * Format app info rows for API responses.
     */
    public static function format(ProjectAppInfo $appInfo): array
    {
        return [
            'id' => (int) $appInfo->id,
            'projectCode' => $appInfo->project_code,
            'appId' => $appInfo->app_id,
            'appName' => $appInfo->app_name,
            'platform' => $appInfo->platform,
            'downloadCount' => (int) ($appInfo->download_count ?? 0),
            'downloadData' => $appInfo->download_data ?? [],
            'iconUrl' => $appInfo->icon_url,
            'chartUrl' => $appInfo->chart_url,
            'imageUrls' => $appInfo->image_urls ?? [],
            'storeUrl' => $appInfo->store_url,
            'enabled' => (int) ($appInfo->enabled ?? 0),
            'remark' => $appInfo->remark,
            'createdAt' => $appInfo->created_at,
            'updatedAt' => $appInfo->updated_at,
        ];
    }

    /**
     * Resolve a project code from request projectCode or projectId.
     */
    private function resolveProjectCode(array $data, bool $required): ?string
    {
        if (array_key_exists('projectCode', $data) && $data['projectCode'] !== null && trim((string) $data['projectCode']) !== '') {
            $projectCode = trim((string) $data['projectCode']);
            if ($required && !Project::query()->where('project_code', $projectCode)->exists()) {
                throw new BusinessException([404, 'Project not found']);
            }

            return $projectCode;
        }

        if (!empty($data['projectId'])) {
            $project = Project::find((int) $data['projectId']);
            if (!$project) {
                throw new BusinessException([404, 'Project not found']);
            }

            return (string) $project->project_code;
        }

        if ($required) {
            throw new BusinessException([422, 'projectCode or projectId is required']);
        }

        return null;
    }

    /**
     * Convert API field names to table columns.
     */
    private function extractAttributes(array $data): array
    {
        $map = [
            'appName' => 'app_name',
            'platform' => 'platform',
            'downloadCount' => 'download_count',
            'downloadData' => 'download_data',
            'iconUrl' => 'icon_url',
            'chartUrl' => 'chart_url',
            'imageUrls' => 'image_urls',
            'storeUrl' => 'store_url',
            'enabled' => 'enabled',
            'remark' => 'remark',
        ];

        $attributes = [];
        foreach ($map as $requestKey => $column) {
            if (!array_key_exists($requestKey, $data)) {
                continue;
            }
            if (in_array($requestKey, ['downloadCount', 'enabled'], true) && $data[$requestKey] === null) {
                continue;
            }

            $attributes[$column] = match ($requestKey) {
                'downloadCount' => (int) $data[$requestKey],
                'enabled' => (int) $data[$requestKey],
                'downloadData' => is_array($data[$requestKey]) ? $data[$requestKey] : [],
                'imageUrls' => $this->normalizeImageUrls($data[$requestKey]),
                default => $this->normalizeOptionalString($data[$requestKey]),
            };
        }

        return $attributes;
    }

    /**
     * Keep image URL arrays compact and stable.
     */
    private function normalizeImageUrls(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($item) => trim((string) $item),
            $value
        ), static fn ($item) => $item !== '')));
    }

    /**
     * Normalize optional string values for nullable columns.
     */
    private function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
