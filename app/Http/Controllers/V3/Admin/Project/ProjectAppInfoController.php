<?php

namespace App\Http\Controllers\V3\Admin\Project;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IdRequest;
use App\Http\Requests\Admin\ProjectAppInfoIndexRequest;
use App\Http\Requests\Admin\ProjectAppInfoStoreRequest;
use App\Http\Requests\Admin\ProjectAppInfoUpdateRequest;
use App\Services\ProjectAppInfoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ProjectAppInfoController extends Controller
{
    public function __construct(
        protected ProjectAppInfoService $service
    ) {}

    /**
     * Query application information records.
     */
    public function index(ProjectAppInfoIndexRequest $request): JsonResponse
    {
        try {
            $result = $this->service->index($request->validated());
            $result['data'] = $result['data']
                ->map(fn ($item) => ProjectAppInfoService::format($item))
                ->values();

            return $this->ok($result);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAppInfo index error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Load one application information record.
     */
    public function detail(IdRequest $request): JsonResponse
    {
        try {
            return $this->ok(ProjectAppInfoService::format(
                $this->service->detail((int) $request->validated()['id'])
            ));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAppInfo detail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Create one application information record.
     */
    public function store(ProjectAppInfoStoreRequest $request): JsonResponse
    {
        try {
            return $this->ok(ProjectAppInfoService::format(
                $this->service->store($request->validated())
            ));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAppInfo store error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Update one application information record.
     */
    public function update(ProjectAppInfoUpdateRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $id = (int) $params['id'];
            unset($params['id']);

            return $this->ok(ProjectAppInfoService::format(
                $this->service->update($id, $params)
            ));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAppInfo update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Delete one application information record.
     */
    public function destroy(IdRequest $request): JsonResponse
    {
        try {
            $this->service->destroy((int) $request->validated()['id']);

            return $this->ok(true);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAppInfo destroy error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
