<?php

namespace App\Http\Controllers\V3\Admin\Project;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProjectResourceIdRequest;
use App\Http\Requests\Admin\ProjectTrafficAccountStoreRequest;
use App\Http\Requests\Admin\ProjectTrafficAccountUpdateRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\ProjectTrafficAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProjectTrafficAccountController extends Controller
{
    public function __construct(
        protected ProjectTrafficAccountService $service
    ) {}

    /**
     * 查询项目已关联流量账号
     * GET /projects/traffic-accounts?project_id=
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
            Log::error('ProjectTrafficAccount index error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增项目流量账号关联
     * POST /projects/traffic-accounts/create
     */
    public function store(ProjectTrafficAccountStoreRequest $request): JsonResponse
    {
        try {
            $projectId = (int) $request->input('projectId');
            $relation = $this->service->store($projectId, $request->validated());

            return $this->ok(['id' => $relation->id]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectTrafficAccount store error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改项目流量账号关联
     * POST /projects/traffic-accounts/update
     */
    public function update(ProjectTrafficAccountUpdateRequest $request): JsonResponse
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
            Log::error('ProjectTrafficAccount update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 删除项目流量账号关联
     * POST /projects/traffic-accounts/delete
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
            Log::error('ProjectTrafficAccount destroy error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
