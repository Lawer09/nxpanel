<?php

namespace App\Http\Controllers\V3\Admin\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectBatchSaveRequest;
use App\Http\Requests\Admin\ProjectBatchUpdateAdStatusRequest;
use App\Http\Requests\Admin\ProjectBatchUpdateAppPlatformRequest;
use App\Http\Requests\Admin\ProjectBatchUpdateDepartmentRequest;
use App\Http\Requests\Admin\ProjectFetchRequest;
use App\Http\Requests\Admin\ProjectAggregateHourlyRequest;
use App\Http\Requests\Admin\ProjectAggregateRequest;
use App\Http\Requests\Admin\ProjectSaveRequest;
use App\Http\Requests\Admin\ProjectUpdateRequest;
use App\Http\Requests\Admin\ProjectUpdateStatusFieldsRequest;
use App\Http\Requests\Admin\ProjectUpdateStatusRequest;
use App\Http\Resources\ProjectResource;
use App\Services\ProjectReportService;
use App\Services\ProjectService;
use App\Jobs\AggregateProjectDailyJob;
use Illuminate\Http\JsonResponse;
use App\Exceptions\BusinessException;
use App\Http\Requests\Admin\IdRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;

class ProjectController extends Controller
{
    public function __construct(
        protected ProjectService $projectService,
        protected ProjectReportService $projectReportService,
    ) {}

    public function index(ProjectFetchRequest $request): JsonResponse
    {
        $data = $this->projectService->fetch($request->validated());

        return $this->ok([
            'page'     => $data['page'],
            'pageSize' => $data['pageSize'],
            'total'    => $data['total'],
            'data'     => ProjectResource::collection($data['items']),
        ]);
    }

    public function detail(IdRequest $request): JsonResponse
    {
        try {
            $id = (int) $request->validated()['id'];
            $project = $this->projectService->detail($id);
            return $this->ok(ProjectResource::make($project));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function store(ProjectSaveRequest $request): JsonResponse
    {
        try {
            $project = $this->projectService->save($request->validated());
            return $this->ok(ProjectResource::make($project));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function update(ProjectUpdateRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $id = (int) $params['id'];
            unset($params['id']);
            $project = $this->projectService->update($id, $params);
            return $this->ok(ProjectResource::make($project));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function updateStatus(ProjectUpdateStatusRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $id = (int) $params['id'];
            $project = $this->projectService->updateStatus($id, $params['status']);
            return $this->ok(ProjectResource::make($project));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    /**
     * Update all project status-related fields exposed to application clients.
     */
    public function updateStatusFields(ProjectUpdateStatusFieldsRequest $request): JsonResponse
    {
        try {
            return $this->ok($this->projectService->updateStatusFields($request->validated()));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function batchUpdateAdStatus(ProjectBatchUpdateAdStatusRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            return $this->ok($this->projectService->batchUpdateAdStatus(
                $params['ids'],
                $params['adStatus'] ?? null
            ));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function batchUpdateAppPlatform(ProjectBatchUpdateAppPlatformRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            return $this->ok($this->projectService->batchUpdateAppPlatform(
                $params['ids'],
                $params['appPlatform'] ?? null
            ));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function batchUpdateDepartment(ProjectBatchUpdateDepartmentRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            return $this->ok($this->projectService->batchUpdateDepartment(
                $params['ids'],
                $params['department'] ?? null
            ));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function departments(): JsonResponse
    {
        return $this->ok([
            'data' => $this->projectService->departments(),
        ]);
    }

    public function projectCodes(): JsonResponse
    {
        return $this->ok([
            'data' => $this->projectService->projectCodes(),
        ]);
    }

    public function batchSave(ProjectBatchSaveRequest $request): JsonResponse
    {
        try {
            return $this->ok($this->projectService->batchSave($request->validated()['items']));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function aggregate(ProjectAggregateRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $startDate = (string) $params['startDate'];
            $endDate = (string) $params['endDate'];
            $projectId = isset($params['projectId']) ? (int) $params['projectId'] : null;

            $arguments = [
                '--start-date' => $startDate,
                '--end-date' => $endDate,
            ];
            if ($projectId !== null) {
                $arguments['--project-id'] = $projectId;
            }

            $exitCode = $this->callArtisanWithFreshDatabase('project:aggregate-daily', $arguments);
            if ($exitCode === 0) {
                $this->projectReportService->refreshQueryCache();
            }

            return $this->ok([
                'success' => $exitCode === 0,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'projectId' => $projectId,
                'exitCode' => $exitCode,
                'output' => trim(Artisan::output()),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAggregate aggregate error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function aggregateHourly(ProjectAggregateHourlyRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $startDate = (string) $params['startDate'];
            $endDate = (string) $params['endDate'];
            $projectId = isset($params['projectId']) ? (int) $params['projectId'] : null;

            $arguments = [
                '--start-date' => $startDate,
                '--end-date' => $endDate,
            ];
            if ($projectId !== null) {
                $arguments['--project-id'] = $projectId;
            }
            if (isset($params['hourFrom'])) {
                $arguments['--hour-from'] = (int) $params['hourFrom'];
            }
            if (isset($params['hourTo'])) {
                $arguments['--hour-to'] = (int) $params['hourTo'];
            }

            $exitCode = $this->callArtisanWithFreshDatabase('project:aggregate-hourly', $arguments);
            if ($exitCode === 0) {
                $this->projectReportService->refreshQueryCache();
            }

            return $this->ok([
                'success' => $exitCode === 0,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'hourFrom' => $params['hourFrom'] ?? null,
                'hourTo' => $params['hourTo'] ?? null,
                'projectId' => $projectId,
                'exitCode' => $exitCode,
                'output' => trim(Artisan::output()),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAggregate aggregateHourly error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function aggregateAsync(ProjectAggregateRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $startDate = (string) $params['startDate'];
            $endDate = (string) $params['endDate'];
            $projectId = isset($params['projectId']) ? (int) $params['projectId'] : null;
            $triggerId = (string) Str::uuid();

            AggregateProjectDailyJob::dispatch($startDate, $endDate, $triggerId, $projectId)->onQueue('default');

            return $this->ok([
                'accepted' => true,
                'triggerId' => $triggerId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'projectId' => $projectId,
                'status' => 'queued',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAggregate aggregateAsync error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Run aggregation commands from HTTP with a clean DB state, matching CLI behavior more closely.
     */
    private function callArtisanWithFreshDatabase(string $command, array $arguments): int
    {
        $this->resetArtisanDatabaseState();

        try {
            return Artisan::call($command, $arguments);
        } finally {
            $this->resetArtisanDatabaseState();
        }
    }

    /**
     * Reset lingering DB state before/after Artisan calls in long-lived workers.
     */
    private function resetArtisanDatabaseState(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::purge();
        DB::reconnect();
    }
}
