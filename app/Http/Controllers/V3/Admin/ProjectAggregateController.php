<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AggregateProjectDailyJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProjectAggregateController extends Controller
{
    public function aggregate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'startDate' => 'required|date',
                'endDate' => 'required|date|after_or_equal:startDate',
            ]);

            $startDate = (string) $request->input('startDate');
            $endDate = (string) $request->input('endDate');

            $exitCode = Artisan::call('project:aggregate-daily', [
                '--start-date' => $startDate,
                '--end-date' => $endDate,
            ]);

            return $this->ok([
                'success' => $exitCode === 0,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'exitCode' => $exitCode,
                'output' => trim(Artisan::output()),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAggregate aggregate error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function aggregateAsync(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'startDate' => 'required|date',
                'endDate' => 'required|date|after_or_equal:startDate',
            ]);

            $startDate = (string) $request->input('startDate');
            $endDate = (string) $request->input('endDate');
            $triggerId = (string) Str::uuid();

            AggregateProjectDailyJob::dispatch($startDate, $endDate, $triggerId)->onQueue('default');

            return $this->ok([
                'accepted' => true,
                'triggerId' => $triggerId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'status' => 'queued',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAggregate aggregateAsync error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function daily(Request $request): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'startDate' => 'required|date',
                'endDate' => 'required|date|after_or_equal:startDate',
                'projectCode' => 'nullable|string|max:100',
                'country' => 'nullable|string|max:50',
                'groupBy' => 'nullable|array|min:1',
                'groupBy.*' => 'string|distinct|in:reportDate,projectCode,country',
                'page' => 'nullable|integer|min:1',
                'pageSize' => 'nullable|integer|min:1|max:200',
                'orderBy' => 'nullable|string|in:reportDate,projectCode,country,adRevenue,adSpendCost,trafficCost,profit,roi,adSpendCpi,updatedAt',
                'orderDir' => 'nullable|string|in:asc,desc',
            ]);

            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 50);
            $orderDir = (string) $request->input('orderDir', 'desc');
            $groupBy = (array) $request->input('groupBy', []);
            $defaultOrderBy = !empty($groupBy) ? (string) $groupBy[0] : 'reportDate';
            $orderBy = (string) $request->input('orderBy', $defaultOrderBy);

            $columnMap = [
                'reportDate' => 'report_date',
                'projectCode' => 'project_code',
                'country' => 'country',
                'adRevenue' => 'ad_revenue',
                'adSpendCost' => 'ad_spend_cost',
                'trafficCost' => 'traffic_cost',
                'profit' => 'profit',
                'roi' => 'roi',
                'adSpendCpi' => 'ad_spend_cpi',
                'updatedAt' => 'updated_at',
            ];

            $aggregateOrderFields = ['adRevenue', 'adSpendCost', 'trafficCost', 'profit', 'roi', 'adSpendCpi', 'updatedAt'];
            $allowedOrderBy = empty($groupBy)
                ? array_keys($columnMap)
                : array_values(array_unique(array_merge($groupBy, $aggregateOrderFields)));

            if (!in_array($orderBy, $allowedOrderBy, true)) {
                $orderBy = $defaultOrderBy;
            }

            $query = DB::table('project_daily_aggregates')
                ->where('report_date', '>=', $request->input('startDate'))
                ->where('report_date', '<=', $request->input('endDate'));

            if ($request->filled('projectCode')) {
                $query->where('project_code', $request->input('projectCode'));
            }
            if ($request->has('country')) {
                $query->where('country', strtoupper((string) $request->input('country', '')));
            }

            if (empty($groupBy)) {
                $total = (clone $query)->count();
                $rows = $query
                    ->orderBy($columnMap[$orderBy], $orderDir)
                    ->offset(($page - 1) * $pageSize)
                    ->limit($pageSize)
                    ->get();
            } else {
                $dimensionMap = [
                    'reportDate' => 'report_date',
                    'projectCode' => 'project_code',
                    'country' => 'country',
                ];

                $groupQuery = clone $query;
                foreach ($groupBy as $dimension) {
                    $groupColumn = $dimensionMap[$dimension];
                    $groupQuery->selectRaw($groupColumn);
                    $groupQuery->groupBy($groupColumn);
                }

                $groupQuery->selectRaw('SUM(new_users) as new_users')
                    ->selectRaw('SUM(dau_users) as dau_users')
                    ->selectRaw('SUM(ad_revenue) as ad_revenue')
                    ->selectRaw('SUM(ad_requests) as ad_requests')
                    ->selectRaw('SUM(ad_matched_requests) as ad_matched_requests')
                    ->selectRaw('SUM(ad_impressions) as ad_impressions')
                    ->selectRaw('SUM(ad_clicks) as ad_clicks')
                    ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
                    ->selectRaw('SUM(traffic_usage_mb) as traffic_usage_mb')
                    ->selectRaw('SUM(traffic_cost) as traffic_cost')
                    ->selectRaw('SUM(profit) as profit')
                    ->selectRaw('MAX(updated_at) as updated_at');

                $groupQuery
                    ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_revenue)/SUM(ad_impressions)*1000,6) END as ad_ecpm')
                    ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_clicks)/SUM(ad_impressions)*100,6) END as ad_ctr')
                    ->selectRaw('CASE WHEN SUM(ad_requests)=0 THEN NULL ELSE ROUND(SUM(ad_matched_requests)/SUM(ad_requests)*100,6) END as ad_match_rate')
                    ->selectRaw('CASE WHEN SUM(ad_matched_requests)=0 THEN NULL ELSE ROUND(SUM(ad_impressions)/SUM(ad_matched_requests)*100,6) END as ad_show_rate')
                    ->selectRaw('CASE WHEN (SUM(ad_spend_cost)+SUM(traffic_cost))=0 THEN NULL ELSE ROUND(SUM(ad_revenue)/(SUM(ad_spend_cost)+SUM(traffic_cost)),6) END as roi')
                    ->selectRaw('CASE WHEN SUM(new_users)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)/SUM(new_users),6) END as ad_spend_cpi')
                    ->selectRaw('CASE WHEN SUM(ad_clicks)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)/SUM(ad_clicks),6) END as ad_spend_cpc')
                    ->selectRaw('CASE WHEN SUM(ad_impressions)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)*1000/SUM(ad_impressions),6) END as ad_spend_cpm');

                $countQuery = DB::table(DB::raw("({$groupQuery->toSql()}) as t"))
                    ->mergeBindings($groupQuery)
                    ->selectRaw('COUNT(*) as cnt')
                    ->first();
                $total = (int) ($countQuery->cnt ?? 0);

                $rows = $groupQuery
                    ->orderBy($columnMap[$orderBy], $orderDir)
                    ->offset(($page - 1) * $pageSize)
                    ->limit($pageSize)
                    ->get();
            }

            $list = $rows->map(function ($row) {
                $reportDate = $row->report_date ?? null;
                $projectCode = $row->project_code ?? null;
                $country = $row->country ?? null;

                return [
                    'id' => isset($row->id) ? (int) $row->id : null,
                    'reportDate' => $reportDate === null ? null : (string) $reportDate,
                    'projectCode' => $projectCode,
                    'country' => $country,
                    'dauUsers' => (int) $row->dau_users,
                    'newUsers' => (int) $row->new_users,
                    'adRevenue' => $this->formatDecimal($row->ad_revenue),
                    'adRequests' => (int) $row->ad_requests,
                    'adMatchedRequests' => (int) $row->ad_matched_requests,
                    'adImpressions' => (int) $row->ad_impressions,
                    'adClicks' => (int) $row->ad_clicks,
                    'adEcpm' => $this->formatDecimal($row->ad_ecpm),
                    'adCtr' => $this->formatDecimal($row->ad_ctr),
                    'adMatchRate' => $this->formatDecimal($row->ad_match_rate),
                    'adShowRate' => $this->formatDecimal($row->ad_show_rate),
                    'adSpendCost' => $this->formatDecimal($row->ad_spend_cost),
                    'adSpendCpi' => $this->formatDecimal($row->ad_spend_cpi),
                    'adSpendCpc' => $this->formatDecimal($row->ad_spend_cpc),
                    'adSpendCpm' => $this->formatDecimal($row->ad_spend_cpm),
                    'trafficUsageMb' => $this->formatDecimal($row->traffic_usage_mb),
                    'trafficCost' => $this->formatDecimal($row->traffic_cost),
                    'profit' => $this->formatDecimal($row->profit),
                    'roi' => $this->formatDecimal($row->roi),
                    'updatedAt' => $row->updated_at,
                ];
            });

            return $this->ok([
                'data' => $list,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAggregate daily error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'startDate' => 'required|date',
                'endDate' => 'required|date|after_or_equal:startDate',
                'projectCode' => 'nullable|string|max:100',
                'country' => 'nullable|string|max:50',
                'groupBy' => 'nullable|string|in:project,country,date',
            ]);

            $groupBy = (string) $request->input('groupBy', 'project');

            $query = DB::table('project_daily_aggregates')
                ->where('report_date', '>=', $request->input('startDate'))
                ->where('report_date', '<=', $request->input('endDate'));

            if ($request->filled('projectCode')) {
                $query->where('project_code', $request->input('projectCode'));
            }
            if ($request->has('country')) {
                $query->where('country', strtoupper((string) $request->input('country', '')));
            }

            if ($groupBy === 'project') {
                $query->selectRaw('project_code as dimension')
                    ->groupBy('project_code');
            } elseif ($groupBy === 'country') {
                $query->selectRaw('country as dimension')
                    ->groupBy('country');
            } else {
                $query->selectRaw('report_date as dimension')
                    ->groupBy('report_date');
            }

            $query->selectRaw('SUM(new_users) as new_users')
                ->selectRaw('SUM(dau_users) as dau_users')
                ->selectRaw('SUM(ad_revenue) as ad_revenue')
                ->selectRaw('SUM(ad_requests) as ad_requests')
                ->selectRaw('SUM(ad_matched_requests) as ad_matched_requests')
                ->selectRaw('SUM(ad_impressions) as ad_impressions')
                ->selectRaw('SUM(ad_clicks) as ad_clicks')
                ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
                ->selectRaw('SUM(traffic_usage_mb) as traffic_usage_mb')
                ->selectRaw('SUM(traffic_cost) as traffic_cost')
                ->selectRaw('SUM(profit) as profit');

            $rows = $query->orderBy('dimension')->get();

            $data = $rows->map(function ($row) use ($groupBy) {
                $adRevenue = (float) ($row->ad_revenue ?? 0);
                $adRequests = (float) ($row->ad_requests ?? 0);
                $matchedRequests = (float) ($row->ad_matched_requests ?? 0);
                $impressions = (float) ($row->ad_impressions ?? 0);
                $clicks = (float) ($row->ad_clicks ?? 0);
                $adSpendCost = (float) ($row->ad_spend_cost ?? 0);
                $trafficCost = (float) ($row->traffic_cost ?? 0);
                $profit = (float) ($row->profit ?? 0);
                $newUsers = (float) ($row->new_users ?? 0);
                $costTotal = $adSpendCost + $trafficCost;

                $item = [
                    'newUsers' => (int) $newUsers,
                    'dauUsers' => (int) ($row->dau_users ?? 0),
                    'adRevenue' => $this->formatDecimal($adRevenue),
                    'adRequests' => (int) $adRequests,
                    'adMatchedRequests' => (int) $matchedRequests,
                    'adImpressions' => (int) $impressions,
                    'adClicks' => (int) $clicks,
                    'adEcpm' => $this->ratio($adRevenue * 1000, $impressions),
                    'adCtr' => $this->ratio($clicks * 100, $impressions),
                    'adMatchRate' => $this->ratio($matchedRequests * 100, $adRequests),
                    'adShowRate' => $this->ratio($impressions * 100, $matchedRequests),
                    'adSpendCost' => $this->formatDecimal($adSpendCost),
                    'adSpendCpi' => $this->ratio($adSpendCost, $newUsers),
                    'adSpendCpc' => $this->ratio($adSpendCost, $clicks),
                    'adSpendCpm' => $this->ratio($adSpendCost * 1000, $impressions),
                    'trafficUsageMb' => $this->formatDecimal($row->traffic_usage_mb),
                    'trafficCost' => $this->formatDecimal($trafficCost),
                    'profit' => $this->formatDecimal($profit),
                    'roi' => $this->ratio($adRevenue, $costTotal),
                ];

                if ($groupBy === 'project') {
                    $item['projectCode'] = $row->dimension;
                } elseif ($groupBy === 'country') {
                    $item['country'] = $row->dimension;
                } else {
                    $item['date'] = (string) $row->dimension;
                }

                return $item;
            });

            return $this->ok($data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAggregate summary error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function trend(Request $request): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'startDate' => 'required|date',
                'endDate' => 'required|date|after_or_equal:startDate',
                'projectCode' => 'nullable|string|max:100',
                'country' => 'nullable|string|max:50',
                'dimension' => 'nullable|string|in:day,month',
            ]);

            $dimension = (string) $request->input('dimension', 'day');
            $timeExpr = $dimension === 'month'
                ? "DATE_FORMAT(report_date, '%Y-%m')"
                : 'report_date';

            $query = DB::table('project_daily_aggregates')
                ->where('report_date', '>=', $request->input('startDate'))
                ->where('report_date', '<=', $request->input('endDate'));

            if ($request->filled('projectCode')) {
                $query->where('project_code', $request->input('projectCode'));
            }
            if ($request->has('country')) {
                $query->where('country', strtoupper((string) $request->input('country', '')));
            }

            $rows = $query->selectRaw($timeExpr . ' as time')
                ->selectRaw('SUM(new_users) as new_users')
                ->selectRaw('SUM(dau_users) as dau_users')
                ->selectRaw('SUM(ad_revenue) as ad_revenue')
                ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
                ->selectRaw('SUM(traffic_usage_mb) as traffic_usage_mb')
                ->selectRaw('SUM(traffic_cost) as traffic_cost')
                ->selectRaw('SUM(profit) as profit')
                ->groupBy(DB::raw($timeExpr))
                ->orderBy('time')
                ->get();

            $data = $rows->map(function ($row) {
                $adSpendCost = (float) ($row->ad_spend_cost ?? 0);
                $trafficCost = (float) ($row->traffic_cost ?? 0);
                $profit = (float) ($row->profit ?? 0);
                $newUsers = (float) ($row->new_users ?? 0);
                $costTotal = $adSpendCost + $trafficCost;

                return [
                    'time' => (string) $row->time,
                    'newUsers' => (int) $newUsers,
                    'dauUsers' => (int) ($row->dau_users ?? 0),
                    'adRevenue' => $this->formatDecimal($row->ad_revenue),
                    'adSpendCost' => $this->formatDecimal($adSpendCost),
                    'adSpendCpi' => $this->ratio($adSpendCost, $newUsers),
                    'trafficUsageMb' => $this->formatDecimal($row->traffic_usage_mb),
                    'trafficCost' => $this->formatDecimal($trafficCost),
                    'profit' => $this->formatDecimal($profit),
                    'roi' => $this->ratio((float) ($row->ad_revenue ?? 0), $costTotal),
                ];
            });

            return $this->ok($data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('ProjectAggregate trend error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    private function ratio(float $a, float $b): ?string
    {
        if ($b == 0.0) {
            return null;
        }

        return $this->formatDecimal($a / $b);
    }

    private function formatDecimal($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 6, '.', '');
    }

    private function normalizeQueryParams(Request $request): void
    {
        $map = [
            'startdate' => 'startDate',
            'enddate' => 'endDate',
            'projectcode' => 'projectCode',
            'country' => 'country',
            'adcountry' => 'country',
            'spendcountry' => 'country',
            'usercountry' => 'country',
            'pagesize' => 'pageSize',
            'orderby' => 'orderBy',
            'orderdir' => 'orderDir',
        ];

        $merged = [];
        foreach ($map as $from => $to) {
            if ($request->has($from) && !$request->has($to)) {
                $merged[$to] = $request->input($from);
            }
        }

        if (!empty($merged)) {
            $request->merge($merged);
        }
    }
}
