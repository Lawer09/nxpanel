<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectFetchRequest;
use App\Http\Requests\Admin\ProjectSaveRequest;
use App\Http\Requests\Admin\ProjectUpdateRequest;
use App\Http\Requests\Admin\ProjectUpdateStatusRequest;
use App\Http\Resources\ProjectResource;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use App\Exceptions\BusinessException;

class ProjectController extends Controller
{
    public function __construct(
        protected ProjectService $projectService
    ) {}

    public function fetch(ProjectFetchRequest $request): JsonResponse
    {
        $data = $this->projectService->fetch($request->validated());

        return $this->ok([
            'page'     => $data['page'],
            'pageSize' => $data['pageSize'],
            'total'    => $data['total'],
            'data'     => ProjectResource::collection($data['items']),
        ]);
    }

    public function detail(int $id): JsonResponse
    {
        try {
            $project = $this->projectService->detail($id);
            return $this->ok(ProjectResource::make($project));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function save(ProjectSaveRequest $request): JsonResponse
    {
        try {
            $project = $this->projectService->save($request->validated());
            return $this->ok(ProjectResource::make($project));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function update(ProjectUpdateRequest $request, int $id): JsonResponse
    {
        try {
            $project = $this->projectService->update($id, $request->validated());
            return $this->ok(ProjectResource::make($project));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }

    public function updateStatus(ProjectUpdateStatusRequest $request, int $id): JsonResponse
    {
        try {
            $project = $this->projectService->updateStatus($id, $request->validated()['status']);
            return $this->ok(ProjectResource::make($project));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        }
    }
}
