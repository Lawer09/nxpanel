<?php

namespace App\Services;

use App\Models\Project;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    private const BATCH_SAVE_CHUNK_SIZE = 100;

    private const PROJECT_BASE_FIELD_MAP = [
        'projectName' => 'project_name',
        'ownerName' => 'owner_name',
        'department' => 'department',
        'status' => 'status',
        'remark' => 'remark',
    ];

    private const PROJECT_METADATA_FIELD_MAP = [
        'adStatus' => 'ad_status',
        'appPlatform' => 'app_platform',
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
        if (!empty($params['appPlatform'])) {
            $query->where('app_platform', $params['appPlatform']);
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
            'department'   => $params['department'] ?? null,
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
        if (array_key_exists('department', $params)) {
            $project->department = $params['department'];
        }
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

    /**
     * Create or update projects by projectCode without touching related tables.
     *
     * @return array{created: int, updated: int, total: int, items: array<int, array{projectCode: string, action: string, id: int}>}
     */
    public function batchSave(array $items): array
    {
        $projectCodes = collect($items)
            ->pluck('projectCode')
            ->map(fn ($code) => trim((string) $code))
            ->filter(fn ($code) => $code !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($projectCodes)) {
            throw new BusinessException([422, 'projectCode cannot be empty']);
        }

        $created = 0;
        $updated = 0;
        $results = [];

        foreach (array_chunk($items, self::BATCH_SAVE_CHUNK_SIZE) as $itemChunk) {
            $chunkCodes = collect($itemChunk)
                ->pluck('projectCode')
                ->map(fn ($code) => trim((string) $code))
                ->filter(fn ($code) => $code !== '')
                ->unique()
                ->values()
                ->all();

            $chunkResult = DB::transaction(function () use ($itemChunk, $chunkCodes) {
                $existingProjects = Project::query()
                    ->whereIn('project_code', $chunkCodes)
                    ->get()
                    ->keyBy('project_code');

                $chunkCreated = 0;
                $chunkUpdated = 0;
                $chunkResults = [];

                foreach ($itemChunk as $item) {
                    $projectCode = trim((string) $item['projectCode']);
                    /** @var Project|null $project */
                    $project = $existingProjects->get($projectCode);

                    if ($project) {
                        $project->fill($this->extractProjectUpdateAttributes($item));
                        if ($project->isDirty()) {
                            $project->save();
                        }
                        $chunkUpdated++;
                        $action = 'updated';
                    } else {
                        $project = Project::create($this->extractProjectCreateAttributes($item));
                        $existingProjects->put($projectCode, $project);
                        $chunkCreated++;
                        $action = 'created';
                    }

                    $chunkResults[] = [
                        'projectCode' => $projectCode,
                        'action' => $action,
                        'id' => (int) $project->id,
                    ];
                }

                return [
                    'created' => $chunkCreated,
                    'updated' => $chunkUpdated,
                    'items' => $chunkResults,
                ];
            });

            $created += $chunkResult['created'];
            $updated += $chunkResult['updated'];
            $results = array_merge($results, $chunkResult['items']);
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => count($items),
            'items' => $results,
        ];
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
        return $this->batchUpdateProjectColumn($ids, 'ad_status', $adStatus);
    }

    /**
     * Batch update project application platform.
     *
     * @return array{requested: int, updated: int, missingIds: array<int>}
     */
    public function batchUpdateAppPlatform(array $ids, ?string $appPlatform): array
    {
        return $this->batchUpdateProjectColumn($ids, 'app_platform', $appPlatform);
    }

    /**
     * Batch update project department.
     *
     * @return array{requested: int, updated: int, missingIds: array<int>}
     */
    public function batchUpdateDepartment(array $ids, ?string $department): array
    {
        $department = is_string($department) ? trim($department) : $department;
        if ($department === '') {
            $department = null;
        }

        return $this->batchUpdateProjectColumn($ids, 'department', $department);
    }

    /**
     * List distinct non-empty departments from existing projects.
     *
     * @return array<int, string>
     */
    public function departments(): array
    {
        return Project::query()
            ->whereNotNull('department')
            ->whereRaw("TRIM(department) <> ''")
            ->distinct()
            ->selectRaw('TRIM(department) as department')
            ->orderBy('department')
            ->pluck('department')
            ->map(fn ($department) => (string) $department)
            ->values()
            ->all();
    }

    /**
     * Batch update one nullable project column and report missing IDs.
     *
     * @return array{requested: int, updated: int, missingIds: array<int>}
     */
    private function batchUpdateProjectColumn(array $ids, string $column, ?string $value): array
    {
        $ids = collect($ids)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new BusinessException([422, '项目ID不能为空']);
        }

        return DB::transaction(function () use ($ids, $column, $value) {
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
                    ->update([$column => $value]);
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

    /**
     * Build attributes for creating a project from a batch-save item.
     */
    private function extractProjectCreateAttributes(array $params): array
    {
        $attributes = [
            'project_code' => trim((string) $params['projectCode']),
            'project_name' => $params['projectName'],
            'owner_name' => $params['ownerName'] ?? null,
            'department' => $params['department'] ?? null,
            'status' => $params['status'] ?? 'active',
            'remark' => $params['remark'] ?? null,
        ];

        return array_merge($attributes, $this->extractMetadataAttributes($params));
    }

    /**
     * Build attributes for updating only fields explicitly present in a batch-save item.
     */
    private function extractProjectUpdateAttributes(array $params): array
    {
        $attributes = [];
        foreach (self::PROJECT_BASE_FIELD_MAP as $requestKey => $column) {
            if (array_key_exists($requestKey, $params)) {
                $attributes[$column] = $params[$requestKey];
            }
        }

        return array_merge($attributes, $this->extractMetadataAttributes($params));
    }
}
