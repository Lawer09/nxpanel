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
                'adCountry' => 'nullable|string|max:50',
                'groupBy' => 'nullable|array|min:1',
                'groupBy.*' => 'string|distinct|in:reportDate,projectCode,adCountry',
                'page' => 'nullable|integer|min:1',
                'pageSize' => 'nullable|integer|min:1|max:200',
                'orderBy' => 'nullable|string|in:reportDate,projectCode,adCountry,revenue,adSpendCost,trafficCost,grossProfit,roi,cpi,updatedAt',
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
                'adCountry' => 'ad_country',
                'revenue' => 'revenue',
                'adSpendCost' => 'ad_spend_cost',
                'trafficCost' => 'traffic_cost',
                'grossProfit' => 'gross_profit',
                'roi' => 'roi',
                'cpi' => 'cpi',
                'updatedAt' => 'updated_at',
            ];

            $query = DB::table('project_daily_aggregates')
                ->where('report_date', '>=', $request->input('startDate'))
                ->where('report_date', '<=', $request->input('endDate'));

            if ($request->filled('projectCode')) {
                $query->where('project_code', $request->input('projectCode'));
            }
            if ($request->has('adCountry')) {
                $query->where('ad_country', (string) $request->input('adCountry', ''));
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
                    'adCountry' => 'ad_country',
                ];

                $groupQuery = clone $query;
                foreach ($groupBy as $dimension) {
                    $groupColumn = $dimensionMap[$dimension];
                    $groupQuery->selectRaw($groupColumn);
                    $groupQuery->groupBy($groupColumn);
                }

                $groupQuery->selectRaw('SUM(report_new_users) as report_new_users')
                    ->selectRaw('SUM(dau_users) as dau_users')
                    ->selectRaw('SUM(register_new_users) as register_new_users')
                    ->selectRaw('SUM(revenue) as revenue')
                    ->selectRaw('SUM(ad_requests) as ad_requests')
                    ->selectRaw('SUM(matched_requests) as matched_requests')
                    ->selectRaw('SUM(impressions) as impressions')
                    ->selectRaw('SUM(clicks) as clicks')
                    ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
                    ->selectRaw('SUM(traffic_usage_gb) as traffic_usage_gb')
                    ->selectRaw('SUM(traffic_cost) as traffic_cost')
                    ->selectRaw('SUM(gross_profit) as gross_profit')
                    ->selectRaw('MAX(updated_at) as updated_at');

                $groupQuery
                    ->selectRaw('CASE WHEN SUM(impressions)=0 THEN NULL ELSE ROUND(SUM(revenue)/SUM(impressions)*1000,6) END as ecpm')
                    ->selectRaw('CASE WHEN SUM(impressions)=0 THEN NULL ELSE ROUND(SUM(clicks)/SUM(impressions)*100,6) END as ctr')
                    ->selectRaw('CASE WHEN SUM(ad_requests)=0 THEN NULL ELSE ROUND(SUM(matched_requests)/SUM(ad_requests)*100,6) END as match_rate')
                    ->selectRaw('CASE WHEN SUM(matched_requests)=0 THEN NULL ELSE ROUND(SUM(impressions)/SUM(matched_requests)*100,6) END as show_rate')
                    ->selectRaw('CASE WHEN (SUM(ad_spend_cost)+SUM(traffic_cost))=0 THEN NULL ELSE ROUND(SUM(gross_profit)/(SUM(ad_spend_cost)+SUM(traffic_cost)),6) END as roi')
                    ->selectRaw('CASE WHEN SUM(report_new_users)=0 THEN NULL ELSE ROUND(SUM(ad_spend_cost)/SUM(report_new_users),6) END as cpi')
                    ->selectRaw('CASE WHEN SUM(impressions)=0 THEN NULL ELSE ROUND(SUM(revenue)/SUM(impressions)*1000,6) END as fb_ecpm');

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
                $adCountry = $row->ad_country ?? null;

                return [
                    'id' => isset($row->id) ? (int) $row->id : null,
                    'reportDate' => $reportDate === null ? null : (string) $reportDate,
                    'projectCode' => $projectCode,
                    'adCountry' => $adCountry,
                    'reportNewUsers' => (int) $row->report_new_users,
                    'dauUsers' => (int) $row->dau_users,
                    'registerNewUsers' => (int) $row->register_new_users,
                    'revenue' => $this->formatDecimal($row->revenue),
                    'adRequests' => (int) $row->ad_requests,
                    'matchedRequests' => (int) $row->matched_requests,
                    'impressions' => (int) $row->impressions,
                    'clicks' => (int) $row->clicks,
                    'ecpm' => $this->formatDecimal($row->ecpm),
                    'ctr' => $this->formatDecimal($row->ctr),
                    'matchRate' => $this->formatDecimal($row->match_rate),
                    'showRate' => $this->formatDecimal($row->show_rate),
                    'adSpendCost' => $this->formatDecimal($row->ad_spend_cost),
                    'trafficUsageGb' => $this->formatDecimal($row->traffic_usage_gb),
                    'trafficCost' => $this->formatDecimal($row->traffic_cost),
                    'grossProfit' => $this->formatDecimal($row->gross_profit),
                    'roi' => $this->formatDecimal($row->roi),
                    'cpi' => $this->formatDecimal($row->cpi),
                    'fbEcpm' => $this->formatDecimal($row->fb_ecpm),
                    'updatedAt' => $row->updated_at,
                ];
            });

            return $this->ok([
                'list' => $list,
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
                'adCountry' => 'nullable|string|max:50',
                'groupBy' => 'nullable|string|in:project,country,date',
            ]);

            $groupBy = (string) $request->input('groupBy', 'project');

            $query = DB::table('project_daily_aggregates')
                ->where('report_date', '>=', $request->input('startDate'))
                ->where('report_date', '<=', $request->input('endDate'));

            if ($request->filled('projectCode')) {
                $query->where('project_code', $request->input('projectCode'));
            }
            if ($request->has('adCountry')) {
                $query->where('ad_country', (string) $request->input('adCountry', ''));
            }

            if ($groupBy === 'project') {
                $query->selectRaw('project_code as dimension')
                    ->groupBy('project_code');
            } elseif ($groupBy === 'country') {
                $query->selectRaw('ad_country as dimension')
                    ->groupBy('ad_country');
            } else {
                $query->selectRaw('report_date as dimension')
                    ->groupBy('report_date');
            }

            $query->selectRaw('SUM(report_new_users) as report_new_users')
                ->selectRaw('SUM(dau_users) as dau_users')
                ->selectRaw('SUM(register_new_users) as register_new_users')
                ->selectRaw('SUM(revenue) as revenue')
                ->selectRaw('SUM(ad_requests) as ad_requests')
                ->selectRaw('SUM(matched_requests) as matched_requests')
                ->selectRaw('SUM(impressions) as impressions')
                ->selectRaw('SUM(clicks) as clicks')
                ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
                ->selectRaw('SUM(traffic_usage_gb) as traffic_usage_gb')
                ->selectRaw('SUM(traffic_cost) as traffic_cost')
                ->selectRaw('SUM(gross_profit) as gross_profit');

            $rows = $query->orderBy('dimension')->get();

            $data = $rows->map(function ($row) use ($groupBy) {
                $revenue = (float) ($row->revenue ?? 0);
                $adRequests = (float) ($row->ad_requests ?? 0);
                $matchedRequests = (float) ($row->matched_requests ?? 0);
                $impressions = (float) ($row->impressions ?? 0);
                $clicks = (float) ($row->clicks ?? 0);
                $adSpendCost = (float) ($row->ad_spend_cost ?? 0);
                $trafficCost = (float) ($row->traffic_cost ?? 0);
                $grossProfit = (float) ($row->gross_profit ?? 0);
                $reportNewUsers = (float) ($row->report_new_users ?? 0);
                $costTotal = $adSpendCost + $trafficCost;

                $item = [
                    'reportNewUsers' => (int) $reportNewUsers,
                    'dauUsers' => (int) ($row->dau_users ?? 0),
                    'registerNewUsers' => (int) ($row->register_new_users ?? 0),
                    'revenue' => $this->formatDecimal($revenue),
                    'adRequests' => (int) $adRequests,
                    'matchedRequests' => (int) $matchedRequests,
                    'impressions' => (int) $impressions,
                    'clicks' => (int) $clicks,
                    'ecpm' => $this->ratio($revenue * 1000, $impressions),
                    'ctr' => $this->ratio($clicks * 100, $impressions),
                    'matchRate' => $this->ratio($matchedRequests * 100, $adRequests),
                    'showRate' => $this->ratio($impressions * 100, $matchedRequests),
                    'adSpendCost' => $this->formatDecimal($adSpendCost),
                    'trafficUsageGb' => $this->formatDecimal($row->traffic_usage_gb),
                    'trafficCost' => $this->formatDecimal($trafficCost),
                    'grossProfit' => $this->formatDecimal($grossProfit),
                    'roi' => $this->ratio($grossProfit, $costTotal),
                    'cpi' => $this->ratio($adSpendCost, $reportNewUsers),
                    'fbEcpm' => $this->ratio($revenue * 1000, $impressions),
                ];

                if ($groupBy === 'project') {
                    $item['projectCode'] = $row->dimension;
                } elseif ($groupBy === 'country') {
                    $item['adCountry'] = $row->dimension;
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
                'adCountry' => 'nullable|string|max:50',
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
            if ($request->has('adCountry')) {
                $query->where('ad_country', (string) $request->input('adCountry', ''));
            }

            $rows = $query->selectRaw($timeExpr . ' as time')
                ->selectRaw('SUM(report_new_users) as report_new_users')
                ->selectRaw('SUM(dau_users) as dau_users')
                ->selectRaw('SUM(register_new_users) as register_new_users')
                ->selectRaw('SUM(revenue) as revenue')
                ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
                ->selectRaw('SUM(traffic_usage_gb) as traffic_usage_gb')
                ->selectRaw('SUM(traffic_cost) as traffic_cost')
                ->selectRaw('SUM(gross_profit) as gross_profit')
                ->groupBy(DB::raw($timeExpr))
                ->orderBy('time')
                ->get();

            $data = $rows->map(function ($row) {
                $adSpendCost = (float) ($row->ad_spend_cost ?? 0);
                $trafficCost = (float) ($row->traffic_cost ?? 0);
                $grossProfit = (float) ($row->gross_profit ?? 0);
                $reportNewUsers = (float) ($row->report_new_users ?? 0);
                $costTotal = $adSpendCost + $trafficCost;

                return [
                    'time' => (string) $row->time,
                    'reportNewUsers' => (int) $reportNewUsers,
                    'dauUsers' => (int) ($row->dau_users ?? 0),
                    'registerNewUsers' => (int) ($row->register_new_users ?? 0),
                    'revenue' => $this->formatDecimal($row->revenue),
                    'adSpendCost' => $this->formatDecimal($adSpendCost),
                    'trafficUsageGb' => $this->formatDecimal($row->traffic_usage_gb),
                    'trafficCost' => $this->formatDecimal($trafficCost),
                    'grossProfit' => $this->formatDecimal($grossProfit),
                    'roi' => $this->ratio($grossProfit, $costTotal),
                    'cpi' => $this->ratio($adSpendCost, $reportNewUsers),
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
            'adcountry' => 'adCountry',
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
