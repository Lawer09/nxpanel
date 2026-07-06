<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Project;
use App\Models\ProjectAppInfo;
use App\Models\ProjectUserAppMap;

class ProjectAppInfoService
{
    /**
     * Query application information records with pagination.
     */
    public function index(array $filters): array
    {
        $query = ProjectAppInfo::query();

        $mappedAppIds = $this->resolveMappedAppIds($filters);
        if ($mappedAppIds !== null) {
            if (empty($mappedAppIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('app_id', $mappedAppIds);
            }
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
                $query->where('app_id', 'like', "%{$keyword}%")
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
     * Load one application information record.
     */
    public function detail(int $id): ProjectAppInfo
    {
        $appInfo = ProjectAppInfo::find($id);
        if (!$appInfo) {
            throw new BusinessException([404, 'App info not found']);
        }

        return $appInfo;
    }

    /**
     * Create one application information record.
     */
    public function store(array $data): ProjectAppInfo
    {
        $appId = trim((string) $data['appId']);
        if ($appId === '') {
            throw new BusinessException([422, 'appId cannot be empty']);
        }

        $exists = ProjectAppInfo::query()
            ->where('app_id', $appId)
            ->exists();
        if ($exists) {
            throw new BusinessException([422, 'App info already exists']);
        }

        return ProjectAppInfo::create(array_merge(
            ['app_id' => $appId],
            $this->extractAttributes($data)
        ));
    }

    /**
     * Update one application information record.
     */
    public function update(int $id, array $data): ProjectAppInfo
    {
        $appInfo = $this->detail($id);
        $attributes = $this->extractAttributes($data);

        if (array_key_exists('appId', $data)) {
            $appId = trim((string) $data['appId']);
            if ($appId === '') {
                throw new BusinessException([422, 'appId cannot be empty']);
            }
            $attributes['app_id'] = $appId;
        }

        if (isset($attributes['app_id'])) {
            $appId = $attributes['app_id'] ?? $appInfo->app_id;
            $exists = ProjectAppInfo::query()
                ->where('app_id', $appId)
                ->where('id', '!=', $appInfo->id)
                ->exists();
            if ($exists) {
                throw new BusinessException([422, 'App info already exists']);
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
     * Delete one application information record.
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
     * Resolve app ids mapped to a project filter, if one was supplied.
     */
    private function resolveMappedAppIds(array $filters): ?array
    {
        $projectCode = null;
        if (!empty($filters['projectId'])) {
            $project = Project::find((int) $filters['projectId']);
            if (!$project) {
                throw new BusinessException([404, 'Project not found']);
            }

            $projectCode = (string) $project->project_code;
        } elseif (array_key_exists('projectCode', $filters) && $filters['projectCode'] !== null && trim((string) $filters['projectCode']) !== '') {
            $projectCode = trim((string) $filters['projectCode']);
        }

        if ($projectCode === null) {
            return null;
        }

        return ProjectUserAppMap::query()
            ->where('project_code', $projectCode)
            ->where('enabled', 1)
            ->pluck('app_id')
            ->map(static fn ($appId) => trim((string) $appId))
            ->filter(static fn ($appId) => $appId !== '')
            ->unique()
            ->values()
            ->all();
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
