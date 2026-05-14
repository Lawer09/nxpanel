<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Project;
use App\Models\ProjectUserAppMap;

class ProjectUserAppMapService
{
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
            'enabled'      => $data['enabled'] ?? 1,
            'remark'       => $data['remark'] ?? null,
        ]);
    }

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
        if (array_key_exists('remark', $data)) {
            $updateData['remark'] = $data['remark'];
        }

        if (!empty($updateData)) {
            $relation->update($updateData);
        }
    }

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
}
