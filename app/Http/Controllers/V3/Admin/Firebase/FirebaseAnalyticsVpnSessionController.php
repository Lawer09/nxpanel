<?php

namespace App\Http\Controllers\V3\Admin\Firebase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseAnalyticsCommonQueryRequest;
use App\Services\FirebaseAnalyticsService;
use Illuminate\Http\JsonResponse;

class FirebaseAnalyticsVpnSessionController extends Controller
{
    public function __construct(
        protected FirebaseAnalyticsService $service
    ) {}

    public function qualityTrend(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->vpnQualityTrend($request->validated()));
    }

    public function summary(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->vpnSummary($request->validated()));
    }

    public function failStageDistribution(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->vpnFailStageDistribution($request->validated()));
    }

    public function errorStageDistribution(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->vpnErrorStageDistribution($request->validated()));
    }

    public function connectTypeAnalysis(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->vpnConnectTypeAnalysis($request->validated()));
    }

    public function protocolQuality(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->vpnProtocolQuality($request->validated()));
    }
}
