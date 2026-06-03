<?php

namespace App\Services;

use App\Models\Project;
use App\Exceptions\BusinessException;

class ProjectService
{
    /**
     * @return array{page: int, pageSize: int, total: int, items: \Illuminate\Database\Eloquent\Collection}
     */
    public function fetch(array $params): array
    {
        $query = Project::query();

        if (!empty($params['keyword'])) {
            $keyword = $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('project_code', 'like', "%{$keyword}%")
                  ->orWhere('project_name', 'like', "%{$keyword}%");
            });
        }
        if (!empty($params['ownerId'])) {
            $query->where('owner_id', $params['ownerId']);
        }
        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        $page     = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $total = $query->count();
        $items = $query->with(['trafficAccounts', 'adAccounts', 'userApps'])
            ->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return compact('page', 'pageSize', 'total', 'items');
    }

    public function detail(int $id): Project
    {
        $project = Project::find($id);

        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        return $project;
    }

    public function save(array $params): Project
    {
        if (Project::where('project_code', $params['projectCode'])->exists()) {
            throw new BusinessException([422, '项目代号已存在']);
        }

        return Project::create([
            'project_code' => $params['projectCode'],
            'project_name' => $params['projectName'],
            'owner_name'   => $params['ownerName'] ?? null,
            'status'       => $params['status'] ?? 'active',
            'remark'       => $params['remark'] ?? null,
        ]);
    }

    public function update(int $id, array $params): Project
    {
        $project = Project::find($id);

        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        if (array_key_exists('projectName', $params)) {
            $project->project_name = $params['projectName'];
        }
        if (array_key_exists('ownerName', $params)) {
            $project->owner_name = $params['ownerName'];
        }
        if (array_key_exists('department', $params)) {
            $project->department = $params['department'];
        }
        if (array_key_exists('status', $params)) {
            $project->status = $params['status'];
        }
        if (array_key_exists('remark', $params)) {
            $project->remark = $params['remark'];
        }

        if ($project->isDirty()) {
            $project->save();
        }

        return $project;
    }

    public function updateStatus(int $id, string $status): Project
    {
        $project = Project::find($id);

        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        $project->update(['status' => $status]);

        return $project;
    }
}
