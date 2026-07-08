<?php

namespace App\Http\Controllers\V3\Admin\TrafficPlatform;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TrafficPlatformAllocationCreateRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\TrafficPlatform\TrafficPlatformAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TrafficPlatformAllocationController extends Controller
{
    public function __construct(
        protected TrafficPlatformAllocationService $service
    ) {}

    /**
     * 手动创建流量划转订单。
     */
    public function store(TrafficPlatformAllocationCreateRequest $request): JsonResponse
    {
        try {
            $result = $this->service->createOrderForAccount($request->validated());

            return $this->ok(CamelizeResource::make($result));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('TrafficPlatformAllocation store error: ' . $e->getMessage());

            return $this->error([500, $e->getMessage()]);
        }
    }
}
