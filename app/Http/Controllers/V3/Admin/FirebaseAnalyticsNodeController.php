<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseAnalyticsNodesQualityRankRequest;
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
}
