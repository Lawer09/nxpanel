<?php

namespace App\Http\Controllers\V3\Admin\Project;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IdRequest;
use App\Http\Requests\Admin\ProjectVersionRecordIndexRequest;
use App\Http\Requests\Admin\ProjectVersionRecordStoreRequest;
use App\Http\Requests\Admin\ProjectVersionRecordUpdateRequest;
use App\Services\ProjectVersionRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ProjectVersionRecordController extends Controller
{
    public function __construct(
        protected ProjectVersionRecordService $service
    ) {}

    /**
     * Query project version records.
     */
    public function index(ProjectVersionRecordIndexRequest $request): JsonResponse
    {
        try {
            $result = $this->service->index($request->validated());
            $result['data'] = $result['data']
                ->map(fn ($item) => ProjectVersionRecordService::format($item))
                ->values();

            return $this->ok($result);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectVersionRecord index error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Load one project version record.
     */
    public function detail(IdRequest $request): JsonResponse
    {
        try {
            return $this->ok(ProjectVersionRecordService::format(
                $this->service->detail((int) $request->validated()['id'])
            ));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectVersionRecord detail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Create one project version record.
     */
    public function store(ProjectVersionRecordStoreRequest $request): JsonResponse
    {
        try {
            return $this->ok(ProjectVersionRecordService::format(
                $this->service->store($request->validated())
            ));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectVersionRecord store error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Update one project version record.
     */
    public function update(ProjectVersionRecordUpdateRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $id = (int) $params['id'];
            unset($params['id']);

            return $this->ok(ProjectVersionRecordService::format(
                $this->service->update($id, $params)
            ));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectVersionRecord update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Delete one project version record.
     */
    public function destroy(IdRequest $request): JsonResponse
    {
        try {
            $this->service->destroy((int) $request->validated()['id']);

            return $this->ok(true);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectVersionRecord destroy error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
