<?php

namespace App\Http\Controllers\V3\Admin\Project;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectBatchUpdateAdStatusRequest;
use App\Http\Requests\Admin\ProjectFetchRequest;
use App\Http\Requests\Admin\ProjectAggregateRequest;
use App\Http\Requests\Admin\ProjectSaveRequest;
use App\Http\Requests\Admin\ProjectUpdateRequest;
use App\Http\Requests\Admin\ProjectUpdateStatusRequest;
use App\Http\Resources\ProjectResource;
use App\Services\ProjectService;
use App\Jobs\AggregateProjectDailyJob;
use Illuminate\Http\JsonResponse;
use App\Exceptions\BusinessException;
use App\Http\Requests\Admin\IdRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;

class ProjectController extends Controller
{
    public function __construct(
        protected ProjectService $projectService,
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

            $exitCode = Artisan::call('project:aggregate-daily', $arguments);

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
}
