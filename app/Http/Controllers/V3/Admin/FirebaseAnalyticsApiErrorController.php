<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseAnalyticsApiRankRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsCommonQueryRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsErrorsTopRequest;
use App\Services\FirebaseAnalyticsService;
use Illuminate\Http\JsonResponse;

class FirebaseAnalyticsApiErrorController extends Controller
{
    public function __construct(
        protected FirebaseAnalyticsService $service
    ) {}

    public function errorsTop(FirebaseAnalyticsErrorsTopRequest $request): JsonResponse
    {
        return $this->ok($this->service->errorsTop($request->validated()));
    }

    public function summary(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->apiErrorSummary($request->validated()));
    }

    public function trend(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->apiErrorTrend($request->validated()));
    }

    public function httpStatusDistribution(FirebaseAnalyticsCommonQueryRequest $request): JsonResponse
    {
        return $this->ok($this->service->apiHttpStatusDistribution($request->validated()));
    }

    public function apiRank(FirebaseAnalyticsApiRankRequest $request): JsonResponse
    {
        return $this->ok($this->service->apiPathRank($request->validated()));
    }
}
