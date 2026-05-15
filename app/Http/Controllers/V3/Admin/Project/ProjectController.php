<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectFetchRequest;
use App\Http\Requests\Admin\ProjectSaveRequest;
use App\Http\Requests\Admin\ProjectUpdateRequest;
use App\Http\Requests\Admin\ProjectUpdateStatusRequest;
use App\Http\Resources\ProjectResource;
use App\Services\ProjectService;
use App\Services\ProjectTrafficAccountService;
use App\Services\ProjectUserAppMapService;
use App\Services\ProjectAdAccountService;
use Illuminate\Http\JsonResponse;
use App\Exceptions\BusinessException;
use App\Http\Requests\Admin\IdRequest;

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
}
