<?php

namespace App\Http\Controllers\V3\Admin\Firebase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseAnalyticsCommonQueryRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsAppVersionRankRequest;
use App\Services\FirebaseAnalyticsService;
use Illuminate\Http\JsonResponse;

class FirebaseAnalyticsAppOpenController extends Controller
{
    public function __construct(
        protected FirebaseAnalyticsService $service
    ) {}

    public function summary(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->appOpenSummary($request->validated()));
    }

    public function trend(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->appOpenTrend($request->validated()));
    }

    public function openTypeDistribution(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->appOpenTypeDistribution($request->validated()));
    }

    public function versionRank(FirebaseAnalyticsAppVersionRankRequest $request): JsonResponse
    {
        return $this->ok($this->service->appOpenVersionRank($request->validated()));
    }
}
