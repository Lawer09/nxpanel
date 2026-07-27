<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\AdPlatformAccount;
use App\Models\AdPlatformApp;
use App\Models\Project;
use App\Models\ProjectAdPlatformAccount;

class ProjectAdAccountService
{
    public function index(int $projectId, array $filters): array
    {
        $project = Project::find($projectId);
        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        $query = ProjectAdPlatformAccount::where('project_id', $projectId);

        if (!empty($filters['platformCode'])) {
            $query->where('platform_code', $filters['platformCode']);
        }
        if (array_key_exists('enabled', $filters) && $filters['enabled'] !== null && $filters['enabled'] !== '') {
            $query->where('enabled', (int) $filters['enabled']);
        }

        $items = $query->orderByDesc('id')->get();

        $accountIds = $items->pluck('ad_platform_account_id')->unique()->filter()->values();
        $accountMap = AdPlatformAccount::whereIn('id', $accountIds)->pluck('account_name', 'id');
        $appIds = $items->pluck('external_app_id')
            ->map(fn ($appId) => trim((string) $appId))
            ->filter(fn ($appId) => $appId !== '')
            ->unique()
            ->values();
        $appMap = ($accountIds->isEmpty() || $appIds->isEmpty())
            ? collect()
            : AdPlatformApp::query()
                ->whereIn('account_id', $accountIds->all())
                ->whereIn('provider_app_id', $appIds->all())
                ->get(['account_id', 'provider_app_id', 'provider_app_name'])
                ->mapWithKeys(fn ($app) => [
                    $this->makeAppMapKey((int) $app->account_id, (string) $app->provider_app_id) => (string) $app->provider_app_name,
                ]);

        $list = $items->map(function ($item) use ($accountMap, $appMap) {
            $arr = $item->toArray();
            $arr['account_name'] = $accountMap[$item->ad_platform_account_id] ?? '';
            $arr['app_name'] = $appMap[$this->makeAppMapKey(
                (int) $item->ad_platform_account_id,
                (string) $item->external_app_id
            )] ?? '';
            return $arr;
        });

        return ['data' => $list->values()];
    }

    public function store(int $projectId, array $data): ProjectAdPlatformAccount
    {
        $project = Project::find($projectId);
        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        $externalAppId    = $data['externalAppId'] ?? '';
        $externalAdUnitId = $data['externalAdUnitId'] ?? '';

        $exists = ProjectAdPlatformAccount::where('project_id', $projectId)
            ->where('ad_platform_account_id', $data['adPlatformAccountId'])
            ->where('external_app_id', $externalAppId)
            ->where('external_ad_unit_id', $externalAdUnitId)
            ->exists();
        if ($exists) {
            throw new BusinessException([422, '该关联已存在']);
        }

        return ProjectAdPlatformAccount::create([
            'project_id'             => $projectId,
            'project_code'           => $project->project_code,
            'ad_platform_account_id' => $data['adPlatformAccountId'],
            'platform_code'          => $data['platformCode'],
            'external_app_id'        => $externalAppId,
            'external_ad_unit_id'    => $externalAdUnitId,
            'bind_type'              => $data['bindType'] ?? 'account',
            'enabled'                => $data['enabled'] ?? 1,
            'remark'                 => $data['remark'] ?? null,
        ]);
    }

    public function update(int $projectId, int $relationId, array $data): void
    {
        $relation = ProjectAdPlatformAccount::where('project_id', $projectId)
            ->where('id', $relationId)
            ->first();
        if (!$relation) {
            throw new BusinessException([404, '关联记录不存在']);
        }

        $updateData = [];
        if (array_key_exists('externalAppId', $data))    $updateData['external_app_id']     = $data['externalAppId'] ?: '';
        if (array_key_exists('externalAdUnitId', $data)) $updateData['external_ad_unit_id'] = $data['externalAdUnitId'] ?: '';
        if (array_key_exists('bindType', $data))         $updateData['bind_type']           = $data['bindType'];
        if (array_key_exists('enabled', $data))          $updateData['enabled']             = $data['enabled'];
        if (array_key_exists('remark', $data))           $updateData['remark']              = $data['remark'];

        if (!empty($updateData)) {
            $relation->update($updateData);
        }
    }

    public function destroy(int $projectId, int $relationId): void
    {
        $relation = ProjectAdPlatformAccount::where('project_id', $projectId)
            ->where('id', $relationId)
            ->first();
        if (!$relation) {
            throw new BusinessException([404, '关联记录不存在']);
        }

        $relation->delete();
    }

    /**
     * Build a stable key for app display-name lookup.
     */
    private function makeAppMapKey(int $accountId, string $providerAppId): string
    {
        return $accountId . ':' . trim($providerAppId);
    }
}
