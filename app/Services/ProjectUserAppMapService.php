<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Project;
use App\Models\ProjectUserAppMap;

class ProjectUserAppMapService
{
    /**
     * Return project code to package name mappings grouped by project code.
     */
    public function mappings(array $filters = []): array
    {
        $query = ProjectUserAppMap::query()
            ->whereNotNull('project_code')
            ->where('project_code', '<>', '')
            ->whereNotNull('app_id')
            ->where('app_id', '<>', '');

        if (!($filters['includeDisabled'] ?? false)) {
            $query->where('enabled', array_key_exists('enabled', $filters) ? (int) $filters['enabled'] : 1);
        } elseif (array_key_exists('enabled', $filters) && $filters['enabled'] !== null && $filters['enabled'] !== '') {
            $query->where('enabled', (int) $filters['enabled']);
        }

        if (!empty($filters['projectCode'])) {
            $query->where('project_code', trim((string) $filters['projectCode']));
        }

        if (!empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($query) use ($keyword): void {
                $query->where('project_code', 'like', "%{$keyword}%")
                    ->orWhere('app_id', 'like', "%{$keyword}%");
            });
        }

        return $query
            ->orderBy('project_code')
            ->orderBy('app_id')
            ->get(['id', 'project_code', 'app_id', 'enabled'])
            ->groupBy('project_code')
            ->map(function ($items, string $projectCode): array {
                $packageNames = $items
                    ->pluck('app_id')
                    ->map(fn($appId) => trim((string) $appId))
                    ->filter(fn(string $appId) => $appId !== '')
                    ->unique()
                    ->values();

                return [
                    'projectCode' => $projectCode,
                    'packageNames' => $packageNames->all(),
                    'appCount' => $packageNames->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Query user app bindings for the specified project.
     */
    public function index(int $projectId, array $filters): array
    {
        $project = Project::find($projectId);
        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        $query = ProjectUserAppMap::where('project_code', $project->project_code);

        if (array_key_exists('enabled', $filters) && $filters['enabled'] !== null && $filters['enabled'] !== '') {
            $query->where('enabled', (int) $filters['enabled']);
        }
        if (!empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where('app_id', 'like', "%{$keyword}%");
        }

        $items = $query->orderByDesc('id')->get();

        return ['data' => $items];
    }

    /**
     * Create a new user app binding for the specified project.
     */
    public function store(int $projectId, array $data): ProjectUserAppMap
    {
        $project = Project::find($projectId);
        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        $appId = trim((string) $data['appId']);
        if ($appId === '') {
            throw new BusinessException([422, 'appId不能为空']);
        }

        $exists = ProjectUserAppMap::where('project_code', $project->project_code)
            ->where('app_id', $appId)
            ->exists();
        if ($exists) {
            throw new BusinessException([422, '该App绑定已存在']);
        }

        return ProjectUserAppMap::create([
            'project_code' => $project->project_code,
            'app_id'       => $appId,
            'app_link'     => $this->normalizeOptionalString($data['appLink'] ?? null),
            'enabled'      => $data['enabled'] ?? 1,
            'remark'       => $this->normalizeOptionalString($data['remark'] ?? null),
        ]);
    }

    /**
     * Update an existing user app binding for the specified project.
     */
    public function update(int $projectId, int $relationId, array $data): void
    {
        $project = Project::find($projectId);
        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        $relation = ProjectUserAppMap::where('project_code', $project->project_code)
            ->where('id', $relationId)
            ->first();
        if (!$relation) {
            throw new BusinessException([404, '关联记录不存在']);
        }

        $updateData = [];

        if (array_key_exists('appId', $data)) {
            $appId = trim((string) $data['appId']);
            if ($appId === '') {
                throw new BusinessException([422, 'appId不能为空']);
            }

            $exists = ProjectUserAppMap::where('project_code', $project->project_code)
                ->where('app_id', $appId)
                ->where('id', '!=', $relationId)
                ->exists();
            if ($exists) {
                throw new BusinessException([422, '该App绑定已存在']);
            }

            $updateData['app_id'] = $appId;
        }

        if (array_key_exists('enabled', $data)) {
            $updateData['enabled'] = (int) $data['enabled'];
        }
        if (array_key_exists('appLink', $data)) {
            $updateData['app_link'] = $this->normalizeOptionalString($data['appLink']);
        }
        if (array_key_exists('remark', $data)) {
            $updateData['remark'] = $this->normalizeOptionalString($data['remark']);
        }

        if (!empty($updateData)) {
            $relation->update($updateData);
        }
    }

    /**
     * Delete an existing user app binding for the specified project.
     */
    public function destroy(int $projectId, int $relationId): void
    {
        $project = Project::find($projectId);
        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        $relation = ProjectUserAppMap::where('project_code', $project->project_code)
            ->where('id', $relationId)
            ->first();
        if (!$relation) {
            throw new BusinessException([404, '关联记录不存在']);
        }

        $relation->delete();
    }

    /**
     * Normalize optional string fields to nullable trimmed values.
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
