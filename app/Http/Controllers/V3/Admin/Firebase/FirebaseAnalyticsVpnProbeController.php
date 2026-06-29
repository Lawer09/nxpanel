<?php

namespace App\Http\Controllers\V3\Admin\Firebase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseAnalyticsCommonQueryRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsProbeNodeStatsRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsProbeNodeRankRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsProbeResultsRequest;
use App\Services\FirebaseAnalyticsService;
use Illuminate\Http\JsonResponse;

class FirebaseAnalyticsVpnProbeController extends Controller
{
    public function __construct(
        protected FirebaseAnalyticsService $service
    ) {}

    public function summary(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->probeSummary($request->validated()));
    }

    public function trend(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->probeTrend($request->validated()));
    }

    public function triggerDistribution(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->probeTriggerDistribution($request->validated()));
    }

    public function typeDistribution(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->probeTypeDistribution($request->validated()));
    }

    public function nodeRank(FirebaseAnalyticsProbeNodeRankRequest $request): JsonResponse
    {
        return $this->ok($this->service->probeNodeRank($request->validated()));
    }

    /**
     * Query Firebase VPN probe node statistics.
     */
    public function nodeStats(FirebaseAnalyticsProbeNodeStatsRequest $request): JsonResponse
    {
        return $this->ok($this->service->probeNodeStats($request->validated()));
    }

    /**
     * Query Firebase VPN probe result details.
     */
    public function results(FirebaseAnalyticsProbeResultsRequest $request): JsonResponse
    {
        return $this->ok($this->service->probeResults($request->validated()));
    }
}
