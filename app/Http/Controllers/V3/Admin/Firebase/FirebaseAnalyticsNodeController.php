<?php

namespace App\Http\Controllers\V3\Admin\Firebase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseAnalyticsNodeConnectionErrorDistributionRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsNodeConnectionResultsRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsNodeConnectionSummaryRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsNodesQualityRankRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsNodesStatusRequest;
use App\Services\FirebaseAnalyticsService;
use Illuminate\Http\JsonResponse;

class FirebaseAnalyticsNodeController extends Controller
{
    public function __construct(
        protected FirebaseAnalyticsService $service
    ) {}

    public function qualityRank(FirebaseAnalyticsNodesQualityRankRequest $request): JsonResponse
    {
        return $this->ok($this->service->nodesQualityRank($request->validated()));
    }

    /**
     * Query merged Firebase node status metrics from connection and probe samples.
     */
    public function status(FirebaseAnalyticsNodesStatusRequest $request): JsonResponse
    {
        return $this->ok($this->service->nodesStatus($request->validated()));
    }

    /**
     * Query connection summary for a single Firebase node view.
     */
    public function connectionSummary(FirebaseAnalyticsNodeConnectionSummaryRequest $request): JsonResponse
    {
        return $this->ok($this->service->nodeConnectionSummary($request->validated()));
    }

    /**
     * Query connection error distribution for a single Firebase node view.
     */
    public function connectionErrorDistribution(FirebaseAnalyticsNodeConnectionErrorDistributionRequest $request): JsonResponse
    {
        return $this->ok($this->service->nodeConnectionErrorDistribution($request->validated()));
    }

    /**
     * Query paginated connection details for a single Firebase node view.
     */
    public function connectionResults(FirebaseAnalyticsNodeConnectionResultsRequest $request): JsonResponse
    {
        return $this->ok($this->service->nodeConnectionResults($request->validated()));
    }
}
