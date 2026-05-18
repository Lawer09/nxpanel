<?php

namespace App\Http\Controllers\V3\Admin\TrafficPlatform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TrafficPlatformUsageDailyRequest;
use App\Http\Requests\Admin\TrafficPlatformUsageHourlyRequest;
use App\Http\Requests\Admin\TrafficPlatformUsageMonthlyRequest;
use App\Http\Requests\Admin\TrafficPlatformUsageRankingRequest;
use App\Http\Requests\Admin\TrafficPlatformUsageTrendRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\TrafficPlatform\TrafficPlatformUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TrafficPlatformUsageController extends Controller
{
    public function __construct(
        protected TrafficPlatformUsageService $service
    ) {}

    /**
     * 小时流量明细
     * GET /traffic-platform/usages/hourly
     */
    public function hourly(TrafficPlatformUsageHourlyRequest $request): JsonResponse
    {
        try {
            $result = $this->service->hourly($request->validated());

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficUsage hourly error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 日流量汇总
     * GET /traffic-platform/usages/daily
     */
    public function daily(TrafficPlatformUsageDailyRequest $request): JsonResponse
    {
        try {
            $result = $this->service->daily($request->validated());

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficUsage daily error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 月流量汇总
     * GET /traffic-platform/usages/monthly
     */
    public function monthly(TrafficPlatformUsageMonthlyRequest $request): JsonResponse
    {
        try {
            $result = $this->service->monthly($request->validated());

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficUsage monthly error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 流量趋势
     * GET /traffic-platform/usages/trend
     */
    public function trend(TrafficPlatformUsageTrendRequest $request): JsonResponse
    {
        try {
            $result = $this->service->trend($request->validated());

            return $this->ok([
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficUsage trend error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 账号流量排行
     * GET /traffic-platform/usages/ranking
     */
    public function ranking(TrafficPlatformUsageRankingRequest $request): JsonResponse
    {
        try {
            $result = $this->service->ranking($request->validated());

            return $this->ok([
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (\Exception $e) {
            Log::error('TrafficUsage ranking error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
