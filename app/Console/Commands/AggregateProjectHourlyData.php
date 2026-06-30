<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateProjectHourlyData extends Command
{
    private const UPSERT_BATCH_SIZE = 500;

    protected $signature = 'project:aggregate-hourly
        {--start-date= : Start date (Y-m-d)}
        {--end-date= : End date (Y-m-d)}
        {--hour-from= : Start hour, 0-23}
        {--hour-to= : End hour, 0-23}
        {--project-id= : Project ID, aggregate only this project}';

    protected $description = 'Aggregate project hourly report data from user report, traffic hourly, and ad revenue hourly sources';

    public function handle(): int
    {
        try {
            $targetProjectCode = $this->resolveTargetProjectCode();

            if ($this->isDefaultRecentHourRun()) {
                $this->info('Start aggregating project hourly data: current and previous hour');
                foreach ($this->recentHourBuckets() as $date => $hours) {
                    $this->aggregateOneDate((string) $date, $hours, $targetProjectCode);
                }
                $this->info('Project hourly aggregate completed.');
                return self::SUCCESS;
            }

            [$startDate, $endDate] = $this->resolveDateRange();
            $hours = $this->resolveHours();

            $projectScope = $targetProjectCode === null ? 'all projects' : "project {$targetProjectCode}";
            $this->info(sprintf(
                'Start aggregating project hourly data: %s ~ %s, hours %s, %s',
                $startDate,
                $endDate,
                implode(',', $hours),
                $projectScope
            ));

            $cursor = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);

            while ($cursor->lte($end)) {
                $this->aggregateOneDate($cursor->toDateString(), $hours, $targetProjectCode);
                $cursor->addDay();
            }

            $this->info('Project hourly aggregate completed.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('project:aggregate-hourly failed', [
                'project_id' => $this->option('project-id'),
                'start_date' => $this->option('start-date'),
                'end_date' => $this->option('end-date'),
                'hour_from' => $this->option('hour-from'),
                'hour_to' => $this->option('hour-to'),
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function resolveDateRange(): array
    {
        $start = $this->option('start-date');
        $end = $this->option('end-date');

        if (empty($start) && empty($end)) {
            $today = now()->toDateString();
            $yesterday = now()->subDay()->toDateString();
            return [$yesterday, $today];
        }

        if (empty($start) || empty($end)) {
            throw new \InvalidArgumentException('start-date and end-date must be provided together');
        }

        $startDate = Carbon::parse($start)->toDateString();
        $endDate = Carbon::parse($end)->toDateString();

        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('start-date cannot be greater than end-date');
        }

        return [$startDate, $endDate];
    }

    private function resolveHours(): array
    {
        $from = $this->option('hour-from');
        $to = $this->option('hour-to');

        if ($from === null && $to === null) {
            return range(0, 23);
        }

        $hourFrom = $from === null || $from === '' ? 0 : (int) $from;
        $hourTo = $to === null || $to === '' ? 23 : (int) $to;

        if ($hourFrom < 0 || $hourFrom > 23 || $hourTo < 0 || $hourTo > 23) {
            throw new \InvalidArgumentException('hour-from and hour-to must be between 0 and 23');
        }

        if ($hourFrom > $hourTo) {
            throw new \InvalidArgumentException('hour-from cannot be greater than hour-to');
        }

        return range($hourFrom, $hourTo);
    }

    private function isDefaultRecentHourRun(): bool
    {
        return empty($this->option('start-date'))
            && empty($this->option('end-date'))
            && $this->option('hour-from') === null
            && $this->option('hour-to') === null;
    }

    /**
     * Build exact date-hour buckets for the scheduler default path.
     */
    private function recentHourBuckets(): array
    {
        $currentHour = now()->startOfHour();
        $previousHour = (clone $currentHour)->subHour();
        $buckets = [];

        foreach ([$previousHour, $currentHour] as $hour) {
            $date = $hour->toDateString();
            $buckets[$date][] = (int) $hour->format('G');
        }

        foreach ($buckets as $date => $hours) {
            $buckets[$date] = array_values(array_unique($hours));
        }

        return $buckets;
    }

    private function resolveTargetProjectCode(): ?string
    {
        $projectId = $this->option('project-id');
        if ($projectId === null || $projectId === '') {
            return null;
        }

        $projectCode = DB::table('project_projects')
            ->where('id', '=', (int) $projectId)
            ->value('project_code');

        $projectCode = trim((string) ($projectCode ?? ''));
        if ($projectCode === '') {
            throw new \InvalidArgumentException('project-id does not exist or has empty project_code');
        }

        return $projectCode;
    }

    /**
     * Aggregate one date into project_report_hourly with real hourly source metrics.
     */
    private function aggregateOneDate(string $date, array $hours, ?string $targetProjectCode): void
    {
        $this->info("Aggregating hourly {$date}...");

        $userMap = $this->filterHourlyMapByProjectCode($this->queryUserMetrics($date, $hours), $targetProjectCode);
        $firstReportMap = $this->filterHourlyMapByProjectCode($this->queryFirstReportUserMetrics($date, $hours), $targetProjectCode);
        $adRevenueMap = $this->filterHourlyMapByProjectCode($this->queryAdRevenueMetrics($date, $hours), $targetProjectCode);
        $adSpendMap = $this->filterHourlyMapByProjectCode($this->queryAdSpendMetrics($date, $hours), $targetProjectCode);

        $projectCodes = $this->buildProjectCodeSetFromHourlyMaps([$userMap, $firstReportMap, $adRevenueMap, $adSpendMap]);

        $trafficProjectCodeQuery = DB::table('project_traffic_platform_accounts')
            ->where('enabled', '=', 1)
            ->whereNotNull('project_code')
            ->where('project_code', '!=', '');

        if ($targetProjectCode !== null) {
            $trafficProjectCodeQuery->where('project_code', '=', $targetProjectCode);
            $projectCodes[$targetProjectCode] = true;
        }

        $trafficProjectCodes = $trafficProjectCodeQuery
            ->pluck('project_code')
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->values()
            ->all();

        foreach ($trafficProjectCodes as $projectCode) {
            $projectCodes[$projectCode] = true;
        }

        $trafficMap = [];
        foreach (array_keys($projectCodes) as $projectCode) {
            foreach ($this->queryTrafficUsageMbByHourCountry($date, $hours, $projectCode) as $key => $metrics) {
                $trafficMap[$key] = $metrics;
            }
        }

        $allKeys = [];
        foreach ([$userMap, $firstReportMap, $adRevenueMap, $adSpendMap, $trafficMap] as $map) {
            foreach (array_keys($map) as $key) {
                $allKeys[$key] = true;
            }
        }

        $this->deleteHourlyReports($date, $hours, $targetProjectCode);

        if (empty($allKeys)) {
            $this->info("No hourly aggregate rows for {$date}");
            return;
        }

        $rows = [];
        $now = now();
        $trafficCostPerMb = 0.16 / 1024;

        foreach (array_keys($allKeys) as $key) {
            [$reportDate, $hour, $projectCode, $country] = $this->parseHourlyDimensionKey($key);
            if ($projectCode === '') {
                continue;
            }

            $adRevenue = $this->decimal($adRevenueMap[$key]['ad_revenue'] ?? 0);
            $adRequests = (int) ($adRevenueMap[$key]['ad_requests'] ?? 0);
            $adMatchedRequests = (int) ($adRevenueMap[$key]['ad_matched_requests'] ?? 0);
            $adImpressions = (int) ($adRevenueMap[$key]['ad_impressions'] ?? 0);
            $adClicks = (int) ($adRevenueMap[$key]['ad_clicks'] ?? 0);

            $dauUsers = (int) ($userMap[$key]['dau_users'] ?? 0);
            $firstReportUsers = (int) ($firstReportMap[$key]['report_new_users'] ?? 0);
            $trafficUsageMb = $this->decimal($trafficMap[$key]['traffic_usage_mb'] ?? 0);
            $trafficCost = round($trafficUsageMb * $trafficCostPerMb, 6);
            $adSpendCost = $this->decimal($adSpendMap[$key]['ad_spend_cost'] ?? 0);
            $adSpendClicks = (int) ($adSpendMap[$key]['ad_spend_clicks'] ?? 0);
            $adSpendImpressions = (int) ($adSpendMap[$key]['ad_spend_impressions'] ?? 0);

            $adEcpm = $this->safeRatio($adRevenue * 1000, $adImpressions);
            $adCtr = $this->safeRatio($adClicks * 100, $adImpressions);
            $adMatchRate = $this->safeRatio($adMatchedRequests * 100, $adRequests);
            $adShowRate = $this->safeRatio($adImpressions * 100, $adMatchedRequests);
            $adSpendCpi = $this->safeRatio($adSpendCost, $firstReportUsers);
            $adSpendCpc = $this->safeRatio($adSpendCost, $adSpendClicks);
            $adSpendCpm = $this->safeRatio($adSpendCost * 1000, $adSpendImpressions);
            $totalCost = round($adSpendCost + $trafficCost, 6);
            $profit = round($adRevenue - $totalCost, 6);
            $roi = $this->safeRatio($adRevenue, $totalCost);

            $rows[] = [
                'report_date' => $reportDate,
                'hour' => $hour,
                'project_code' => $projectCode,
                'country' => $country,
                'dau_users' => $dauUsers,
                'new_users' => $firstReportUsers,
                'report_new_users' => $firstReportUsers,
                'fb_new_users' => 0,
                'fb_dau_users' => 0,
                'ad_revenue' => $adRevenue,
                'ad_requests' => $adRequests,
                'ad_matched_requests' => $adMatchedRequests,
                'ad_impressions' => $adImpressions,
                'ad_clicks' => $adClicks,
                'ad_ecpm' => $adEcpm,
                'ad_ctr' => $adCtr,
                'ad_match_rate' => $adMatchRate,
                'ad_show_rate' => $adShowRate,
                'ad_spend_cost' => round($adSpendCost, 6),
                'ad_spend_cpi' => $adSpendCpi,
                'ad_spend_cpc' => $adSpendCpc,
                'ad_spend_cpm' => $adSpendCpm,
                'traffic_usage_mb' => round($trafficUsageMb, 6),
                'traffic_cost' => $trafficCost,
                'profit' => $profit,
                'roi' => $roi,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->upsertInBatches(
            'project_report_hourly',
            $rows,
            ['report_date', 'hour', 'project_code', 'country'],
            [
                'dau_users',
                'new_users',
                'report_new_users',
                'fb_new_users',
                'fb_dau_users',
                'ad_revenue',
                'ad_requests',
                'ad_matched_requests',
                'ad_impressions',
                'ad_clicks',
                'ad_ecpm',
                'ad_ctr',
                'ad_match_rate',
                'ad_show_rate',
                'ad_spend_cost',
                'ad_spend_cpi',
                'ad_spend_cpc',
                'ad_spend_cpm',
                'traffic_usage_mb',
                'traffic_cost',
                'profit',
                'roi',
                'updated_at',
            ]
        );
    }

    private function queryUserMetrics(string $date, array $hours): array
    {
        $rows = DB::table('v3_user_report_count as urc')
            ->join('project_user_app_map as puam', function ($join) {
                $join->on('puam.app_id', '=', 'urc.app_id')
                    ->where('puam.enabled', '=', 1);
            })
            ->where('urc.date', '=', $date)
            ->whereIn('urc.hour', $hours)
            ->selectRaw('urc.date as report_date')
            ->selectRaw('urc.hour as report_hour')
            ->selectRaw('puam.project_code as project_code')
            ->selectRaw('UPPER(COALESCE(urc.client_country, "")) as country')
            ->selectRaw('COUNT(DISTINCT urc.user_id) as dau_users')
            ->groupBy('urc.date', 'urc.hour', 'puam.project_code')
            ->groupByRaw('UPPER(COALESCE(urc.client_country, ""))')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $country = $this->normalizeCountry((string) ($row->country ?? ''));
            $key = $this->makeHourlyDimensionKey((string) $row->report_date, (int) ($row->report_hour ?? 0), (string) $row->project_code, $country);
            $result[$key] = [
                'dau_users' => (int) ($row->dau_users ?? 0),
            ];
        }

        return $result;
    }

    private function queryFirstReportUserMetrics(string $date, array $hours): array
    {
        $rows = DB::table('v3_user_report_count as urc')
            ->join(DB::raw('(
                SELECT user_id, MIN(CONCAT(date, " ", LPAD(hour, 2, "0"))) as first_report_hour
                FROM v3_user_report_count
                GROUP BY user_id
            ) as first_seen'), function ($join) {
                $join->on('first_seen.user_id', '=', 'urc.user_id')
                    ->whereRaw('CONCAT(urc.date, " ", LPAD(urc.hour, 2, "0")) = first_seen.first_report_hour');
            })
            ->join('project_user_app_map as puam', function ($join) {
                $join->on('puam.app_id', '=', 'urc.app_id')
                    ->where('puam.enabled', '=', 1);
            })
            ->where('urc.date', '=', $date)
            ->whereIn('urc.hour', $hours)
            ->selectRaw('urc.date as report_date')
            ->selectRaw('urc.hour as report_hour')
            ->selectRaw('puam.project_code as project_code')
            ->selectRaw('UPPER(COALESCE(urc.client_country, "")) as country')
            ->selectRaw('COUNT(DISTINCT urc.user_id) as report_new_users')
            ->groupBy('urc.date', 'urc.hour', 'puam.project_code')
            ->groupByRaw('UPPER(COALESCE(urc.client_country, ""))')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $country = $this->normalizeCountry((string) ($row->country ?? ''));
            $key = $this->makeHourlyDimensionKey((string) $row->report_date, (int) ($row->report_hour ?? 0), (string) $row->project_code, $country);
            $result[$key] = [
                'report_new_users' => (int) ($row->report_new_users ?? 0),
            ];
        }

        return $result;
    }

    private function queryAdRevenueMetrics(string $date, array $hours): array
    {
        $rows = DB::table('ad_revenue_hourly as ar')
            ->leftJoin('project_ad_platform_accounts as papa', function ($join) {
                $join->on('papa.platform_code', '=', 'ar.source_platform')
                    ->on('papa.ad_platform_account_id', '=', 'ar.account_id')
                    ->where('papa.enabled', '=', 1)
                    ->where(function ($query) {
                        $query->where(function ($appBind) {
                            $appBind->where('papa.bind_type', '!=', 'account')
                                ->whereNotNull('papa.external_app_id')
                                ->where('papa.external_app_id', '!=', '')
                                ->whereColumn('papa.external_app_id', 'ar.provider_app_id');
                        })->orWhere(function ($accountBind) {
                            $accountBind->where('papa.bind_type', '=', 'account')
                                ->whereNotExists(function ($sub) {
                                    $sub->select(DB::raw(1))
                                        ->from('project_ad_platform_accounts as papa2')
                                        ->whereColumn('papa2.platform_code', 'ar.source_platform')
                                        ->whereColumn('papa2.ad_platform_account_id', 'ar.account_id')
                                        ->where('papa2.enabled', '=', 1)
                                        ->where('papa2.bind_type', '!=', 'account')
                                        ->whereNotNull('papa2.external_app_id')
                                        ->where('papa2.external_app_id', '!=', '')
                                        ->whereColumn('papa2.external_app_id', 'ar.provider_app_id');
                                });
                        });
                    });
            })
            ->leftJoin('project_projects as p', 'p.project_code', '=', 'papa.project_code')
            ->where('ar.report_date', '=', $date)
            ->whereIn(DB::raw('HOUR(ar.report_hour)'), $hours)
            ->whereNotNull('p.project_code')
            ->selectRaw('ar.report_date as report_date')
            ->selectRaw('HOUR(ar.report_hour) as report_hour')
            ->selectRaw('p.project_code as project_code')
            ->selectRaw('UPPER(COALESCE(ar.country_code, "")) as country')
            ->selectRaw('SUM(ar.estimated_earnings) as ad_revenue')
            ->selectRaw('SUM(ar.ad_requests) as ad_requests')
            ->selectRaw('SUM(ar.matched_requests) as ad_matched_requests')
            ->selectRaw('SUM(ar.impressions) as ad_impressions')
            ->selectRaw('SUM(ar.clicks) as ad_clicks')
            ->groupBy('ar.report_date', DB::raw('HOUR(ar.report_hour)'), 'p.project_code')
            ->groupByRaw('UPPER(COALESCE(ar.country_code, ""))')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $country = $this->normalizeCountry((string) ($row->country ?? ''));
            $key = $this->makeHourlyDimensionKey((string) $row->report_date, (int) ($row->report_hour ?? 0), (string) $row->project_code, $country);
            $current = $result[$key] ?? [
                'ad_revenue' => 0.0,
                'ad_requests' => 0,
                'ad_matched_requests' => 0,
                'ad_impressions' => 0,
                'ad_clicks' => 0,
            ];
            $result[$key] = [
                'ad_revenue' => $current['ad_revenue'] + $this->decimal($row->ad_revenue),
                'ad_requests' => $current['ad_requests'] + (int) ($row->ad_requests ?? 0),
                'ad_matched_requests' => $current['ad_matched_requests'] + (int) ($row->ad_matched_requests ?? 0),
                'ad_impressions' => $current['ad_impressions'] + (int) ($row->ad_impressions ?? 0),
                'ad_clicks' => $current['ad_clicks'] + (int) ($row->ad_clicks ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Query hourly ad spend metrics by project and country.
     */
    private function queryAdSpendMetrics(string $date, array $hours): array
    {
        $rows = DB::table('ad_spend_report_hourly')
            ->where('report_date', '=', $date)
            ->whereIn('hour', $hours)
            ->select('report_date', 'hour', 'project_code', 'country')
            ->selectRaw('SUM(spend) as ad_spend_cost')
            ->selectRaw('SUM(clicks) as ad_spend_clicks')
            ->selectRaw('SUM(impressions) as ad_spend_impressions')
            ->groupBy('report_date', 'hour', 'project_code', 'country')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $country = $this->normalizeCountry((string) ($row->country ?? ''));
            $key = $this->makeHourlyDimensionKey((string) $row->report_date, (int) ($row->hour ?? 0), (string) $row->project_code, $country);
            $current = $result[$key] ?? [
                'ad_spend_cost' => 0.0,
                'ad_spend_clicks' => 0,
                'ad_spend_impressions' => 0,
            ];
            $result[$key] = [
                'ad_spend_cost' => $current['ad_spend_cost'] + $this->decimal($row->ad_spend_cost),
                'ad_spend_clicks' => $current['ad_spend_clicks'] + (int) ($row->ad_spend_clicks ?? 0),
                'ad_spend_impressions' => $current['ad_spend_impressions'] + (int) ($row->ad_spend_impressions ?? 0),
            ];
        }

        return $result;
    }

    private function queryTrafficUsageMbByHourCountry(string $date, array $hours, string $projectCode): array
    {
        $accountRelations = DB::table('project_traffic_platform_accounts')
            ->where('project_code', '=', $projectCode)
            ->where('enabled', '=', 1)
            ->select('traffic_platform_account_id', 'external_uid')
            ->get();

        if ($accountRelations->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($accountRelations->groupBy('traffic_platform_account_id') as $accountId => $rows) {
            $uidList = collect($rows)
                ->pluck('external_uid')
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn ($value) => $value !== '')
                ->unique()
                ->values()
                ->all();

            $hasAccountLevel = collect($rows)->contains(function ($row) {
                return trim((string) ($row->external_uid ?? '')) === '';
            });

            $usageQuery = DB::table('traffic_platform_usage_hourly')
                ->where('report_date', '=', $date)
                ->where('platform_account_id', '=', (int) $accountId)
                ->whereIn(DB::raw('HOUR(report_hour)'), $hours);

            if (!$hasAccountLevel) {
                if (empty($uidList)) {
                    continue;
                }
                $usageQuery->whereIn('external_uid', $uidList);
            }

            $usageRows = $usageQuery
                ->selectRaw('HOUR(report_hour) as report_hour')
                ->selectRaw('UPPER(COALESCE(geo, "")) as country')
                ->selectRaw('SUM(traffic_mb) as traffic_usage_mb')
                ->groupByRaw('HOUR(report_hour), UPPER(COALESCE(geo, ""))')
                ->get();

            foreach ($usageRows as $row) {
                $country = $this->normalizeCountry((string) ($row->country ?? ''));
                $key = $this->makeHourlyDimensionKey($date, (int) ($row->report_hour ?? 0), $projectCode, $country);
                $result[$key]['traffic_usage_mb'] = ($result[$key]['traffic_usage_mb'] ?? 0.0) + $this->decimal($row->traffic_usage_mb);
            }
        }

        return $result;
    }

    private function deleteHourlyReports(string $date, array $hours, ?string $targetProjectCode): void
    {
        DB::table('project_report_hourly')
            ->where('report_date', '=', $date)
            ->whereIn('hour', $hours)
            ->when($targetProjectCode !== null, fn ($query) => $query->where('project_code', '=', $targetProjectCode))
            ->delete();
    }

    private function buildProjectCodeSetFromHourlyMaps(array $maps): array
    {
        $set = [];
        foreach ($maps as $map) {
            foreach (array_keys($map) as $key) {
                [, , $projectCode] = $this->parseHourlyDimensionKey($key);
                if ($projectCode !== '') {
                    $set[$projectCode] = true;
                }
            }
        }

        return $set;
    }

    private function filterHourlyMapByProjectCode(array $map, ?string $targetProjectCode): array
    {
        if ($targetProjectCode === null) {
            return $map;
        }

        $filtered = [];
        foreach ($map as $key => $value) {
            [, , $projectCode] = $this->parseHourlyDimensionKey((string) $key);
            if ($projectCode === $targetProjectCode) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    private function makeHourlyDimensionKey(string $date, int $hour, string $projectCode, string $country): string
    {
        $safeHour = max(0, min(23, $hour));
        return trim($date) . "\t" . $safeHour . "\t" . trim($projectCode) . "\t" . $this->normalizeCountry($country);
    }

    private function parseHourlyDimensionKey(string $key): array
    {
        $parts = explode("\t", $key, 4);
        return [
            trim((string) ($parts[0] ?? '')),
            max(0, min(23, (int) ($parts[1] ?? 0))),
            trim((string) ($parts[2] ?? '')),
            $this->normalizeCountry((string) ($parts[3] ?? '')),
        ];
    }

    private function normalizeCountry(?string $country): string
    {
        $value = strtoupper(trim((string) ($country ?? '')));
        return $value === '' ? 'XX' : $value;
    }

    private function decimal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }

    private function safeRatio($a, $b): ?float
    {
        $numerator = (float) $a;
        $denominator = (float) $b;

        if ($denominator == 0.0) {
            return null;
        }

        return round($numerator / $denominator, 6);
    }

    private function upsertInBatches(string $table, array $rows, array $uniqueBy, array $updateColumns): void
    {
        foreach (array_chunk($rows, self::UPSERT_BATCH_SIZE) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $updateColumns);
        }
    }
}
