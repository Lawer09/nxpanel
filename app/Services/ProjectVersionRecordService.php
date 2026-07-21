<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Project;
use App\Models\ProjectVersionRecord;
use Illuminate\Support\Facades\DB;

class ProjectVersionRecordService
{
    private const BATCH_CREATE_CHUNK_SIZE = 100;

    /**
     * Query project version records with pagination.
     */
    public function index(array $filters): array
    {
        $query = ProjectVersionRecord::query();

        if (!empty($filters['projectId'])) {
            $query->where('project_id', (int) $filters['projectId']);
        }
        if (!empty($filters['projectCode'])) {
            $query->where('project_code', trim((string) $filters['projectCode']));
        }
        if (!empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($query) use ($keyword): void {
                $query->where('version', 'like', "%{$keyword}%")
                    ->orWhere('version_name', 'like', "%{$keyword}%")
                    ->orWhere('content', 'like', "%{$keyword}%");
            });
        }
        if (!empty($filters['releaseTimeFrom'])) {
            $query->where('release_time', '>=', $filters['releaseTimeFrom']);
        }
        if (!empty($filters['releaseTimeTo'])) {
            $query->where('release_time', '<=', $filters['releaseTimeTo']);
        }

        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 20);
        $total = $query->count();
        $items = $query->orderByDesc('release_time')
            ->orderByDesc('id')
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
     * Load one project version record.
     */
    public function detail(int $id): ProjectVersionRecord
    {
        $record = ProjectVersionRecord::find($id);
        if (!$record) {
            throw new BusinessException([404, '版本记录不存在']);
        }

        return $record;
    }

    /**
     * Create one project version record.
     */
    public function store(array $data): ProjectVersionRecord
    {
        $project = $this->loadProject((int) $data['projectId']);

        return ProjectVersionRecord::create(array_merge(
            [
                'project_id' => (int) $project->id,
                'project_code' => (string) $project->project_code,
            ],
            $this->extractAttributes($data)
        ));
    }

    /**
     * Create multiple project version records in batches.
     *
     * @return array{created: int, total: int, items: array<int, ProjectVersionRecord>}
     */
    public function batchStore(array $items): array
    {
        if (empty($items)) {
            throw new BusinessException([422, '版本记录不能为空']);
        }

        $projectIds = collect($items)
            ->pluck('projectId')
            ->map(fn ($projectId) => (int) $projectId)
            ->filter(fn ($projectId) => $projectId > 0)
            ->unique()
            ->values();

        $projects = Project::query()
            ->whereIn('id', $projectIds->all())
            ->get(['id', 'project_code'])
            ->keyBy('id');

        if ($projects->count() !== $projectIds->count()) {
            throw new BusinessException([404, '项目不存在']);
        }

        $records = DB::transaction(function () use ($items, $projects) {
            $createdRecords = [];
            foreach (array_chunk($items, self::BATCH_CREATE_CHUNK_SIZE) as $itemChunk) {
                foreach ($itemChunk as $item) {
                    $project = $projects->get((int) $item['projectId']);
                    if (!$project) {
                        throw new BusinessException([404, '项目不存在']);
                    }

                    $createdRecords[] = ProjectVersionRecord::create(array_merge(
                        [
                            'project_id' => (int) $project->id,
                            'project_code' => (string) $project->project_code,
                        ],
                        $this->extractAttributes($item)
                    ));
                }
            }

            return $createdRecords;
        });

        return [
            'created' => count($records),
            'total' => count($items),
            'items' => $records,
        ];
    }

    /**
     * Update one project version record.
     */
    public function update(int $id, array $data): ProjectVersionRecord
    {
        $record = $this->detail($id);
        $attributes = $this->extractAttributes($data);

        if (array_key_exists('projectId', $data)) {
            $project = $this->loadProject((int) $data['projectId']);
            $attributes['project_id'] = (int) $project->id;
            $attributes['project_code'] = (string) $project->project_code;
        }

        if (!empty($attributes)) {
            $record->fill($attributes);
            if ($record->isDirty()) {
                $record->save();
            }
        }

        return $record;
    }

    /**
     * Delete one project version record.
     */
    public function destroy(int $id): void
    {
        $record = $this->detail($id);
        $record->delete();
    }

    /**
     * Format project version records for API responses.
     */
    public static function format(ProjectVersionRecord $record): array
    {
        return [
            'id' => (int) $record->id,
            'projectId' => (int) $record->project_id,
            'projectCode' => $record->project_code,
            'version' => $record->version,
            'versionName' => $record->version_name,
            'content' => $record->content,
            'releaseTime' => $record->release_time,
            'remark' => $record->remark,
            'createdAt' => $record->created_at,
            'updatedAt' => $record->updated_at,
        ];
    }

    /**
     * Convert API field names to table columns.
     */
    private function extractAttributes(array $data): array
    {
        $map = [
            'version' => 'version',
            'versionName' => 'version_name',
            'content' => 'content',
            'releaseTime' => 'release_time',
            'remark' => 'remark',
        ];

        $attributes = [];
        foreach ($map as $requestKey => $column) {
            if (!array_key_exists($requestKey, $data)) {
                continue;
            }

            $attributes[$column] = match ($requestKey) {
                'version' => $this->normalizeRequiredString($data[$requestKey], '版本不能为空'),
                'versionName' => $this->normalizeOptionalString($data[$requestKey]),
                'content' => $this->normalizeRequiredString($data[$requestKey], '版本内容不能为空'),
                'remark' => $this->normalizeOptionalString($data[$requestKey]),
                default => $data[$requestKey],
            };
        }

        return $attributes;
    }

    /**
     * Load the owning project and keep project code snapshots consistent.
     */
    private function loadProject(int $projectId): Project
    {
        $project = Project::find($projectId);
        if (!$project) {
            throw new BusinessException([404, '项目不存在']);
        }

        return $project;
    }

    /**
     * Normalize required text fields and reject blank values.
     */
    private function normalizeRequiredString(mixed $value, string $message): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            throw new BusinessException([422, $message]);
        }

        return $normalized;
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
