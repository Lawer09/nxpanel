<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectAppInfo;
use App\Models\ProjectUserAppMap;
use App\Exceptions\BusinessException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    private const BATCH_SAVE_CHUNK_SIZE = 100;

    private const DEPARTMENT_CACHE_KEY = 'project:departments';

    private const DEPARTMENT_CACHE_TTL = 300;

    private const PROJECT_CODE_CACHE_KEY = 'project:project_codes';

    private const PROJECT_CODE_CACHE_TTL = 300;

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
                  ->orWhere('project_name', 'like', "%{$keyword}%")
                  ->orWhereHas('userApps', function ($userAppQuery) use ($keyword) {
                      $userAppQuery->where('app_id', 'like', "%{$keyword}%");
                  });
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
        $this->attachAppInfos($items);

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

        $metadataAttributes = $this->extractMetadataAttributes($params);
        if (!array_key_exists('adStatus', $params)) {
            $metadataAttributes['ad_status'] = Project::AD_STATUS_NOT_LAUNCHED;
        }

        $project = Project::create(array_merge($attributes, $metadataAttributes));

        $this->forgetProjectCodeCache();
        if ($this->hasDisplayDepartment($project->department)) {
            $this->forgetDepartmentCache();
        }

        $project->loadMissing(['trafficAccounts', 'adAccounts', 'userApps']);
        $this->attachAppInfos(collect([$project]));

        return $project;
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

        $departmentChanged = $project->isDirty('department');
        if ($project->isDirty()) {
            $project->save();
        }
        if ($departmentChanged) {
            $this->forgetDepartmentCache();
        }

        $project->loadMissing(['trafficAccounts', 'adAccounts', 'userApps']);
        $this->attachAppInfos(collect([$project]));

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

        if ($this->itemsMayChangeDepartments($items)) {
            $this->forgetDepartmentCache();
        }
        if ($created > 0) {
            $this->forgetProjectCodeCache();
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

        $project->loadMissing(['trafficAccounts', 'adAccounts', 'userApps']);
        $this->attachAppInfos(collect([$project]));

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

        $result = $this->batchUpdateProjectColumn($ids, 'department', $department);
        if ($result['updated'] > 0) {
            $this->forgetDepartmentCache();
        }

        return $result;
    }

    /**
     * List distinct non-empty departments from existing projects.
     *
     * @return array<int, string>
     */
    public function departments(): array
    {
        return Cache::remember(self::DEPARTMENT_CACHE_KEY, self::DEPARTMENT_CACHE_TTL, function () {
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
        });
    }

    /**
     * List distinct non-empty project codes from existing projects.
     *
     * @return array<int, string>
     */
    public function projectCodes(): array
    {
        return Cache::remember(self::PROJECT_CODE_CACHE_KEY, self::PROJECT_CODE_CACHE_TTL, function () {
            return Project::query()
                ->whereNotNull('project_code')
                ->whereRaw("TRIM(project_code) <> ''")
                ->distinct()
                ->selectRaw('TRIM(project_code) as project_code')
                ->orderBy('project_code')
                ->pluck('project_code')
                ->map(fn ($projectCode) => (string) $projectCode)
                ->values()
                ->all();
        });
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

        $metadataAttributes = $this->extractMetadataAttributes($params);
        if (!array_key_exists('adStatus', $params)) {
            $metadataAttributes['ad_status'] = Project::AD_STATUS_NOT_LAUNCHED;
        }

        return array_merge($attributes, $metadataAttributes);
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

    /**
     * Determine whether batch-save input can affect cached department options.
     */
    private function itemsMayChangeDepartments(array $items): bool
    {
        foreach ($items as $item) {
            if (is_array($item) && array_key_exists('department', $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether a department should appear in the enum list.
     */
    private function hasDisplayDepartment(mixed $department): bool
    {
        return is_string($department) && trim($department) !== '';
    }

    /**
     * Clear cached department options after project department changes.
     */
    private function forgetDepartmentCache(): void
    {
        Cache::forget(self::DEPARTMENT_CACHE_KEY);
    }

    /**
     * Clear cached project code options after projects are created.
     */
    private function forgetProjectCodeCache(): void
    {
        Cache::forget(self::PROJECT_CODE_CACHE_KEY);
    }

    /**
     * Attach app info records to projects through project_user_app_map.
     */
    private function attachAppInfos($projects): void
    {
        $projectCodes = $projects
            ->pluck('project_code')
            ->map(fn ($projectCode) => trim((string) $projectCode))
            ->filter(fn ($projectCode) => $projectCode !== '')
            ->unique()
            ->values();

        if ($projectCodes->isEmpty()) {
            return;
        }

        $appIdsByProject = ProjectUserAppMap::query()
            ->whereIn('project_code', $projectCodes->all())
            ->where('enabled', 1)
            ->get(['project_code', 'app_id'])
            ->groupBy('project_code')
            ->map(fn ($items) => $items
                ->pluck('app_id')
                ->map(fn ($appId) => trim((string) $appId))
                ->filter(fn ($appId) => $appId !== '')
                ->unique()
                ->values());

        $allAppIds = $appIdsByProject
            ->flatMap(fn ($appIds) => $appIds)
            ->unique()
            ->values();

        $appInfosByAppId = $allAppIds->isEmpty()
            ? collect()
            : ProjectAppInfo::query()
                ->whereIn('app_id', $allAppIds->all())
                ->orderByDesc('enabled')
                ->orderBy('app_id')
                ->get()
                ->keyBy('app_id');

        foreach ($projects as $project) {
            $appInfos = ($appIdsByProject[$project->project_code] ?? collect())
                ->map(fn ($appId) => $appInfosByAppId[$appId] ?? null)
                ->filter()
                ->values();

            $project->setRelation('appInfos', $appInfos);
        }
    }
}
