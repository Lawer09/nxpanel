<?php

namespace App\Http\Controllers\V3\Admin\Firebase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseAnalyticsFiltersOptionsRequest;
use App\Services\FirebaseAnalyticsService;
use Illuminate\Http\JsonResponse;

class FirebaseAnalyticsFilterController extends Controller
{
    public function __construct(
        protected FirebaseAnalyticsService $service
    ) {}

    public function options(FirebaseAnalyticsFiltersOptionsRequest $request): JsonResponse
    {
        return $this->ok($this->service->filterOptions($request->validated()));
    }
}
