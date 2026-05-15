<?php

namespace App\Http\Controllers\V3\Admin\Project;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectResourceIdRequest;
use App\Http\Requests\Admin\ProjectUserAppMapStoreRequest;
use App\Http\Requests\Admin\ProjectUserAppMapUpdateRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\ProjectUserAppMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectUserAppMapController extends Controller
{
    public function __construct(
        protected ProjectUserAppMapService $service
    ) {}

    /**
     * 查询项目用户App绑定
     * GET /projects/user-apps?project_id=
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $projectId = (int) $request->query('project_id');
            if ($projectId <= 0) {
                return $this->error([422, 'project_id 参数缺失']);
            }

            $result = $this->service->index($projectId, $request->all());
            $result['data'] = CamelizeResource::collection($result['data']);

            return $this->ok($result);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectUserAppMap index error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增项目用户App绑定
     * POST /projects/user-apps/create
     */
    public function store(ProjectUserAppMapStoreRequest $request): JsonResponse
    {
        try {
            $projectId = (int) $request->input('projectId');
            $relation = $this->service->store($projectId, $request->validated());

            return $this->ok(['id' => $relation->id]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectUserAppMap store error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改项目用户App绑定
     * POST /projects/user-apps/update
     */
    public function update(ProjectUserAppMapUpdateRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $projectId  = (int) $params['projectId'];
            $relationId = (int) $params['id'];
            $this->service->update($projectId, $relationId, $params);

            return $this->ok(true);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectUserAppMap update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 删除项目用户App绑定
     * POST /projects/user-apps/delete
     */
    public function destroy(ProjectResourceIdRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $projectId  = (int) $params['projectId'];
            $relationId = (int) $params['id'];
            $this->service->destroy($projectId, $relationId);

            return $this->ok(true);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectUserAppMap destroy error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
