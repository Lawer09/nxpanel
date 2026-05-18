<?php

namespace App\Http\Controllers\V3\Admin\TrafficPlatform;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TrafficPlatformIndexRequest;
use App\Http\Requests\Admin\TrafficPlatformStoreRequest;
use App\Http\Requests\Admin\TrafficPlatformUpdateRequest;
use App\Http\Requests\Admin\TrafficPlatformUpdateStatusRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\TrafficPlatform\TrafficPlatformService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TrafficPlatformController extends Controller
{
    public function __construct(
        protected TrafficPlatformService $service
    ) {}

    /**
     * 平台列表
     * GET /traffic-platform/platforms
     */
    public function index(TrafficPlatformIndexRequest $request): JsonResponse
    {
        try {
            $result = $this->service->index($request->validated());

            return $this->ok([
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatform index error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增平台
     * POST /traffic-platform/platforms/create
     */
    public function store(TrafficPlatformStoreRequest $request): JsonResponse
    {
        try {
            $platform = $this->service->store($request->validated());

            return $this->ok(CamelizeResource::make($platform));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatform store error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 修改平台
     * POST /traffic-platform/platforms/update
     */
    public function update(TrafficPlatformUpdateRequest $request): JsonResponse
    {
        try {
            $platform = $this->service->update($request->validated());
            return $this->ok(CamelizeResource::make($platform));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatform update error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 启用/禁用平台
     * POST /traffic-platform/platforms/update-status
     */
    public function updateStatus(TrafficPlatformUpdateStatusRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $this->service->updateStatus((int) $params['id'], (int) $params['enabled']);

            return $this->ok(true);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatform updateStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
