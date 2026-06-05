<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NodeReportQueryRequest;
use App\Http\Requests\Admin\NodeServerRealtimeRequest;
use App\Http\Requests\Admin\NodeServerReportNodeQueryRequest;
use App\Http\Requests\Admin\NodeServerReportUserQueryRequest;
use App\Http\Requests\Admin\ProjectAggregateDailyQueryRequest;
use App\Http\Requests\Admin\ProjectReportHourlyQueryRequest;
use App\Http\Requests\Admin\UserReportHourlyQueryRequest;
use App\Http\Requests\Admin\UserReportNodeFailQueryRequest;
use App\Http\Requests\Admin\UserReportNodeSummaryQueryRequest;
use App\Http\Requests\Admin\UserReportSummaryQueryRequest;
use App\Http\Requests\Admin\UserReportTrafficQueryRequest;
use App\Services\ProjectReportService;
use App\Services\ReportQueryService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(
        protected ProjectReportService $projectReportService,
        protected ReportQueryService $reportQueryService
    ) {}

    /**
     * Query cached realtime node report data.
     */
    public function nodeServerRealtime(NodeServerRealtimeRequest $request): JsonResponse
    {
        return $this->ok(
            $this->reportQueryService->queryNodeServerRealtime($request->validated())
        );
    }

    /**
     * Query user report summary data.
     */
    public function queryUserReportSummary(UserReportSummaryQueryRequest $request): JsonResponse
    {
        return $this->ok(
            $this->reportQueryService->queryUserReportSummary($request->validated())
        );
    }

    /**
     * Query user report node summary data.
     */
    public function queryUserReportNodeSummary(UserReportNodeSummaryQueryRequest $request): JsonResponse
    {
        return $this->ok(
            $this->reportQueryService->queryUserReportNodeSummary($request->validated())
        );
    }

    /**
     * Query user traffic report data.
     */
    public function queryUserReportTraffic(UserReportTrafficQueryRequest $request): JsonResponse
    {
        return $this->ok(
            $this->reportQueryService->queryUserReportTraffic($request->validated())
        );
    }

    /**
     * Query node failure detail data.
     */
    public function queryUserReportNodeFail(UserReportNodeFailQueryRequest $request): JsonResponse
    {
        return $this->ok(
            $this->reportQueryService->queryUserReportNodeFail($request->validated())
        );
    }

    /**
     * Query node server report on node dimension.
     */
    public function queryNodeServerReportNode(NodeServerReportNodeQueryRequest $request): JsonResponse
    {
        return $this->ok(
            $this->reportQueryService->queryNodeServerReportNode($request->validated())
        );
    }

    /**
     * Query node server report on user dimension.
     */
    public function queryNodeServerReportUser(NodeServerReportUserQueryRequest $request): JsonResponse
    {
        return $this->ok(
            $this->reportQueryService->queryNodeServerReportUser($request->validated())
        );
    }

    /**
     * Query merged node hourly report data.
     */
    public function queryNodeReport(NodeReportQueryRequest $request): JsonResponse
    {
        return $this->ok(
            $this->reportQueryService->queryNodeReport($request->validated())
        );
    }

    /**
     * Query merged user hourly report data.
     */
    public function queryUserReportHourly(UserReportHourlyQueryRequest $request): JsonResponse
    {
        return $this->ok(
            $this->reportQueryService->queryUserReportHourly($request->validated())
        );
    }

    /**
     * Query project daily aggregate report.
     */
    public function queryProjectReport(ProjectAggregateDailyQueryRequest $request): JsonResponse
    {
        return $this->ok(
            $this->projectReportService->queryDaily($request->validated())
        );
    }

    /**
     * Query project hourly report.
     */
    public function queryProjectReportHourly(ProjectReportHourlyQueryRequest $request): JsonResponse
    {
        return $this->ok(
            $this->projectReportService->queryHourly($request->validated())
        );
    }
}
