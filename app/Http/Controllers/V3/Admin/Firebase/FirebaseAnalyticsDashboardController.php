<?php

namespace App\Http\Controllers\V3\Admin\Firebase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseAnalyticsCommonQueryRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsRegionQualityRequest;
use App\Services\FirebaseAnalyticsService;
use Illuminate\Http\JsonResponse;

class FirebaseAnalyticsDashboardController extends Controller
{
    public function __construct(
        protected FirebaseAnalyticsService $service
    ) {}

    public function summary(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->dashboardSummary($request->validated()));
    }

    public function eventTrend(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->eventTrend($request->validated()));
    }

    public function regionQuality(FirebaseAnalyticsRegionQualityRequest $request): JsonResponse
    {
        return $this->ok($this->service->regionQuality($request->validated()));
    }
}
