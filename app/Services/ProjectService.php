<?php

namespace App\Services;

use App\Models\Project;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    private const PROJECT_METADATA_FIELD_MAP = [
        'adStatus' => 'ad_status',
        'adspowerEnv' => 'adspower_env',
        'developerGmail' => 'developer_gmail',
        'appName' => 'app_name',
        'packageName' => 'package_name',
        'domainInfoStatus' => 'domain_info_status',
        'admobPubId' => 'admob_pub_id',
        'domainUrl' => 'domain_url',
        'privacyPolicyUrl' => 'privacy_policy_url',
        'termsUrl' => 'terms_url',
        'facebookInfoStatus' => 'facebook_info_status',
        'facebookAppId' => 'facebook_app_id',
        'facebookAppToken' => 'facebook_app_token',
        'facebookKeyHash' => 'facebook_key_hash',
        'facebookClassName' => 'facebook_class_name',
        'admobAccountStatus' => 'admob_account_status',
        'admobAppId' => 'admob_app_id',
        'admobAdIds' => 'admob_ad_ids',
        'admobAppAdsTxt' => 'admob_app_ads_txt',
        'firebaseConfigNote' => 'firebase_config_note',
        'yandexAccount' => 'yandex_account',
        'yandexAdIds' => 'yandex_ad_ids',
        'yandexAppAdsTxt' => 'yandex_app_ads_txt',
        'storePageUrl' => 'store_page_url',
    ];

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
        if (!empty($params['adStatus'])) {
            $query->where('ad_status', $params['adStatus']);
        }
        if (!empty($params['packageName'])) {
            $query->where('package_name', $params['packageName']);
        }
        if (!empty($params['developerGmail'])) {
            $query->where('developer_gmail', $params['developerGmail']);
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

        $attributes = [
            'project_code' => $params['projectCode'],
            'project_name' => $params['projectName'],
            'owner_name'   => $params['ownerName'] ?? null,
            'status'       => $params['status'] ?? 'active',
            'remark'       => $params['remark'] ?? null,
        ];

        return Project::create(array_merge($attributes, $this->extractMetadataAttributes($params)));
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
        // if (array_key_exists('department', $params)) {
        //     $project->department = $params['department'];
        // }
        if (array_key_exists('status', $params)) {
            $project->status = $params['status'];
        }
        if (array_key_exists('remark', $params)) {
            $project->remark = $params['remark'];
        }
        foreach ($this->extractMetadataAttributes($params) as $column => $value) {
            $project->{$column} = $value;
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

    /**
     * Batch update project ad delivery status.
     *
     * @return array{requested: int, updated: int, missingIds: array<int>}
     */
    public function batchUpdateAdStatus(array $ids, ?string $adStatus): array
    {
        $ids = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new BusinessException([422, '项目ID不能为空']);
        }

        return DB::transaction(function () use ($ids, $adStatus) {
            $existingIds = Project::query()
                ->whereIn('id', $ids->all())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values();

            $missingIds = $ids->diff($existingIds)->values()->all();
            $updated = 0;

            if ($existingIds->isNotEmpty()) {
                $updated = Project::query()
                    ->whereIn('id', $existingIds->all())
                    ->update(['ad_status' => $adStatus]);
            }

            return [
                'requested' => $ids->count(),
                'updated' => $updated,
                'missingIds' => $missingIds,
            ];
        });
    }

    /**
     * Convert project metadata request keys from camelCase to table columns.
     */
    private function extractMetadataAttributes(array $params): array
    {
        $attributes = [];
        foreach (self::PROJECT_METADATA_FIELD_MAP as $requestKey => $column) {
            if (array_key_exists($requestKey, $params)) {
                $attributes[$column] = $params[$requestKey];
            }
        }

        return $attributes;
    }
}
