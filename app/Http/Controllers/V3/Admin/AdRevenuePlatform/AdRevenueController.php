<?php

namespace App\Http\Controllers\V3\Admin\AdRevenuePlatform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdPlatformAppFetch;
use App\Http\Requests\Admin\AdRevenueAggregate;
use App\Http\Requests\Admin\AdRevenueFetch;
use App\Http\Requests\Admin\AdRevenueNowDiffRequest;
use App\Http\Requests\Admin\AdRevenueNowRequest;
use App\Http\Requests\Admin\AdRevenueSummary;
use App\Http\Requests\Admin\AdRevenueTopRank;
use App\Http\Requests\Admin\AdRevenueTrend;
use App\Services\AdRevenueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AdRevenueController extends Controller
{
    public function __construct(private readonly AdRevenueService $adRevenueService)
    {
    }

    /**
     * Query paginated ad revenue detail rows.
     */
    public function fetch(AdRevenueFetch $request): JsonResponse
    {
        return $this->ok($this->adRevenueService->fetch($request->validated()));
    }

    /**
     * Query aggregated ad revenue by requested dimensions.
     */
    public function aggregate(AdRevenueAggregate $request): JsonResponse
    {
        return $this->ok($this->adRevenueService->aggregate($request->validated()));
    }

    /**
     * Query revenue trend and optional comparison trend.
     */
    public function trend(AdRevenueTrend $request): JsonResponse
    {
        return $this->ok($this->adRevenueService->trend($request->validated()));
    }

    /**
     * Query ad revenue summary metrics.
     */
    public function summary(AdRevenueSummary $request): JsonResponse
    {
        return $this->ok($this->adRevenueService->summary($request->validated()));
    }

    /**
     * Query ad platform app list with account metadata.
     */
    public function fetchApps(AdPlatformAppFetch $request): JsonResponse
    {
        try {
            return $this->ok($this->adRevenueService->fetchApps($request->validated()));
        } catch (\Exception $e) {
            Log::error('AdRevenue fetchApps error: ' . $e->getMessage());

            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * Query top rank data by the requested dimension and metric.
     */
    public function topRank(AdRevenueTopRank $request): JsonResponse
    {
        return $this->ok($this->adRevenueService->topRank($request->validated()));
    }

    /**
     * Compare current revenue snapshot with finalized daily revenue.
     */
    public function nowDiff(AdRevenueNowDiffRequest $request): JsonResponse
    {
        return $this->ok($this->adRevenueService->nowDiff($request->validated()));
    }

    /**
     * Query current revenue snapshot data.
     */
    public function now(AdRevenueNowRequest $request): JsonResponse
    {
        return $this->ok($this->adRevenueService->now($request->validated()));
    }
}
