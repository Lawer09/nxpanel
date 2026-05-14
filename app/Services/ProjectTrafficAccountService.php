<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Project;
use App\Models\ProjectTrafficPlatformAccount;
use App\Models\TrafficPlatformAccount;
use Illuminate\Support\Collection;

class ProjectTrafficAccountService
{
    public function index(int $projectId, array $filters): array
    {
        $project = Project::find($projectId);
        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        $query = ProjectTrafficPlatformAccount::where('project_id', $projectId);

        if (!empty($filters['platformCode'])) {
            $query->where('platform_code', $filters['platformCode']);
        }
        if (array_key_exists('enabled', $filters) && $filters['enabled'] !== null && $filters['enabled'] !== '') {
            $query->where('enabled', (int) $filters['enabled']);
        }

        $items = $query->orderByDesc('id')->get();

        $accountIds = $items->pluck('traffic_platform_account_id')->unique()->filter()->values();
        $accountMap = TrafficPlatformAccount::whereIn('id', $accountIds)->pluck('account_name', 'id');

        $list = $items->map(function ($item) use ($accountMap) {
            $arr = $item->toArray();
            $arr['account_name'] = $accountMap[$item->traffic_platform_account_id] ?? '';
            return $arr;
        });

        return ['data' => $list->values()];
    }

    public function store(int $projectId, array $data): ProjectTrafficPlatformAccount
    {
        $project = Project::find($projectId);
        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        $externalUid = $data['externalUid'] ?? '';

        $exists = ProjectTrafficPlatformAccount::where('project_id', $projectId)
            ->where('traffic_platform_account_id', $data['trafficPlatformAccountId'])
            ->where('external_uid', $externalUid)
            ->exists();
        if ($exists) {
            throw new BusinessException([422, '该关联已存在']);
        }

        return ProjectTrafficPlatformAccount::create([
            'project_id'                   => $projectId,
            'project_code'                 => $project->project_code,
            'traffic_platform_account_id'  => $data['trafficPlatformAccountId'],
            'platform_code'                => $data['platformCode'],
            'external_uid'                 => $externalUid,
            'external_username'            => $data['externalUsername'] ?? '',
            'bind_type'                    => $data['bindType'] ?? 'account',
            'enabled'                      => $data['enabled'] ?? 1,
            'remark'                       => $data['remark'] ?? null,
        ]);
    }

    public function update(int $projectId, int $relationId, array $data): void
    {
        $relation = ProjectTrafficPlatformAccount::where('project_id', $projectId)
            ->where('id', $relationId)
            ->first();
        if (!$relation) {
            throw new BusinessException([404, '关联记录不存在']);
        }

        $updateData = [];
        if (array_key_exists('externalUid', $data))     $updateData['external_uid']     = $data['externalUid'] ?: '';
        if (array_key_exists('externalUsername', $data)) $updateData['external_username'] = $data['externalUsername'] ?: '';
        if (array_key_exists('bindType', $data))        $updateData['bind_type']         = $data['bindType'];
        if (array_key_exists('enabled', $data))         $updateData['enabled']           = $data['enabled'];
        if (array_key_exists('remark', $data))          $updateData['remark']            = $data['remark'];

        if (!empty($updateData)) {
            $relation->update($updateData);
        }
    }

    public function destroy(int $projectId, int $relationId): void
    {
        $relation = ProjectTrafficPlatformAccount::where('project_id', $projectId)
            ->where('id', $relationId)
            ->first();
        if (!$relation) {
            throw new BusinessException([404, '关联记录不存在']);
        }

        $relation->delete();
    }
}
