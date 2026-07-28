<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DashboardIncomeSummaryRequest;
use App\Services\ProjectReportService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ProjectReportService $projectReportService,
    ) {}

    public function incomeSummary(DashboardIncomeSummaryRequest $request): JsonResponse
    {
        $params = $request->validated();

        return $this->ok(
            $this->projectReportService->queryDashboardIncomeSummary($params['appId'] ?? null)
        );
    }
}
