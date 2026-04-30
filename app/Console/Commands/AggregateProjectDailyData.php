<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateProjectDailyData extends Command
{
    protected $signature = 'project:aggregate-daily
        {--start-date= : 开始日期(Y-m-d)}
        {--end-date= : 结束日期(Y-m-d)}';

    protected $description = '聚合项目日报数据（默认仅更新当天，支持指定日期范围回补）';

    public function handle(): int
    {
        try {
            [$startDate, $endDate] = $this->resolveDateRange();

            $this->info("Start aggregating project daily data: {$startDate} ~ {$endDate}");

            $cursor = Carbon::parse($startDate);
            $end = Carbon::parse($endDate);

            while ($cursor->lte($end)) {
                $date = $cursor->toDateString();
                $this->aggregateOneDate($date);
                $cursor->addDay();
            }

            $this->info('Project daily aggregate completed.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('project:aggregate-daily failed', [
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
            return [$today, $today];
        }

        if (empty($start) || empty($end)) {
            throw new \InvalidArgumentException('start-date 和 end-date 需要同时传入');
        }

        $startDate = Carbon::parse($start)->toDateString();
        $endDate = Carbon::parse($end)->toDateString();

        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('start-date 不能大于 end-date');
        }

        return [$startDate, $endDate];
    }

    private function aggregateOneDate(string $date): void
    {
        $this->info("Aggregating {$date}...");

        $adRevenueMap = $this->queryAdRevenueMetrics($date);
        $adSpendMap = $this->queryAdSpendMetrics($date);
        $userMap = $this->queryUserMetrics($date);

        $projectCodes = $this->buildProjectCodeSetFromDimensionMaps([$adRevenueMap, $adSpendMap, $userMap]);

        $trafficProjectCodes = DB::table('project_traffic_platform_accounts')
            ->where('enabled', '=', 1)
            ->pluck('project_code')
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values()
            ->all();

        foreach ($trafficProjectCodes as $projectCode) {
            $projectCodes[$projectCode] = true;
        }

        $trafficMap = [];
        foreach (array_keys($projectCodes) as $projectCode) {
            $countryMbMap = $this->queryTrafficUsageMbByCountry($date, $projectCode);
            foreach ($countryMbMap as $country => $trafficUsageMb) {
                $key = $this->makeDimensionKey($date, $projectCode, $country);
                $trafficMap[$key] = [
                    'traffic_usage_mb' => round($this->decimal($trafficUsageMb), 6),
                ];
            }
        }

        $allKeys = [];
        foreach ([$adRevenueMap, $adSpendMap, $userMap, $trafficMap] as $map) {
            foreach (array_keys($map) as $key) {
                $allKeys[$key] = true;
            }
        }

        if (empty($allKeys)) {
            $this->info("No aggregate rows for {$date}");
            return;
        }

        $rows = [];
        $now = now();
        $trafficCostPerMb = 0.16 / 1024;

        foreach (array_keys($allKeys) as $key) {
            [$reportDate, $projectCode, $country] = $this->parseDimensionKey($key);

            $adRevenue = $this->decimal($adRevenueMap[$key]['ad_revenue'] ?? 0);
            $adRequests = (int) ($adRevenueMap[$key]['ad_requests'] ?? 0);
            $adMatchedRequests = (int) ($adRevenueMap[$key]['ad_matched_requests'] ?? 0);
            $adImpressions = (int) ($adRevenueMap[$key]['ad_impressions'] ?? 0);
            $adClicks = (int) ($adRevenueMap[$key]['ad_clicks'] ?? 0);

            $dauUsers = (int) ($userMap[$key]['dau_users'] ?? 0);
            $newUsers = (int) ($userMap[$key]['new_users'] ?? 0);

            $adSpendCost = $this->decimal($adSpendMap[$key]['ad_spend_cost'] ?? 0);
            $adSpendClicks = (int) ($adSpendMap[$key]['ad_spend_clicks'] ?? 0);
            $adSpendImpressions = (int) ($adSpendMap[$key]['ad_spend_impressions'] ?? 0);

            $trafficUsageMb = $this->decimal($trafficMap[$key]['traffic_usage_mb'] ?? 0);
            $trafficCost = round($trafficUsageMb * $trafficCostPerMb, 6);

            $adEcpm = $this->safeRatio($adRevenue * 1000, $adImpressions);
            $adCtr = $this->safeRatio($adClicks * 100, $adImpressions);
            $adMatchRate = $this->safeRatio($adMatchedRequests * 100, $adRequests);
            $adShowRate = $this->safeRatio($adImpressions * 100, $adMatchedRequests);

            $adSpendCpi = $this->safeRatio($adSpendCost, $newUsers);
            $adSpendCpc = $this->safeRatio($adSpendCost, $adSpendClicks);
            $adSpendCpm = $this->safeRatio($adSpendCost * 1000, $adSpendImpressions);

            $profit = round($adRevenue - $adSpendCost - $trafficCost, 6);
            $totalCost = round($adSpendCost + $trafficCost, 6);
            $roi = $this->safeRatio($adRevenue, $totalCost);

            $rows[] = [
                'report_date' => $reportDate,
                'project_code' => $projectCode,
                'country' => $country,
                'dau_users' => $dauUsers,
                'new_users' => $newUsers,
                'ad_revenue' => $adRevenue,
                'ad_requests' => $adRequests,
                'ad_matched_requests' => $adMatchedRequests,
                'ad_impressions' => $adImpressions,
                'ad_clicks' => $adClicks,
                'ad_ecpm' => $adEcpm,
                'ad_ctr' => $adCtr,
                'ad_match_rate' => $adMatchRate,
                'ad_show_rate' => $adShowRate,
                'ad_spend_cost' => $adSpendCost,
                'ad_spend_cpi' => $adSpendCpi,
                'ad_spend_cpc' => $adSpendCpc,
                'ad_spend_cpm' => $adSpendCpm,
                'traffic_usage_mb' => round($trafficUsageMb, 6),
                'traffic_cost' => $trafficCost,
                'profit' => $profit,
                'roi' => $roi,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        DB::table('project_daily_aggregates')->upsert(
            $rows,
            ['report_date', 'project_code', 'country'],
            [
                'dau_users',
                'new_users',
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

    private function queryAdRevenueMetrics(string $date): array
    {
        $rows = DB::table('ad_revenue_daily as ar')
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
            ->whereNotNull('p.project_code')
            ->selectRaw('ar.report_date as report_date')
            ->selectRaw('p.project_code as project_code')
            ->selectRaw('UPPER(COALESCE(ar.country_code, "")) as country')
            ->selectRaw('SUM(ar.estimated_earnings) as ad_revenue')
            ->selectRaw('SUM(ar.ad_requests) as ad_requests')
            ->selectRaw('SUM(ar.matched_requests) as ad_matched_requests')
            ->selectRaw('SUM(ar.impressions) as ad_impressions')
            ->selectRaw('SUM(ar.clicks) as ad_clicks')
            ->groupBy(['ar.report_date', 'p.project_code', DB::raw('UPPER(COALESCE(ar.country_code, ""))')])
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $country = $this->normalizeCountry((string) ($row->country ?? ''));
            $key = $this->makeDimensionKey((string) $row->report_date, (string) $row->project_code, $country);
            $result[$key] = [
                'ad_revenue' => $this->decimal($row->ad_revenue),
                'ad_requests' => (int) ($row->ad_requests ?? 0),
                'ad_matched_requests' => (int) ($row->ad_matched_requests ?? 0),
                'ad_impressions' => (int) ($row->ad_impressions ?? 0),
                'ad_clicks' => (int) ($row->ad_clicks ?? 0),
            ];
        }

        return $result;
    }

    private function queryAdSpendMetrics(string $date): array
    {
        $rows = DB::table('ad_spend_platform_daily_reports')
            ->where('report_date', '=', $date)
            ->selectRaw('report_date as report_date')
            ->selectRaw('project_code as project_code')
            ->selectRaw('UPPER(COALESCE(country, "")) as country')
            ->selectRaw('SUM(spend) as ad_spend_cost')
            ->selectRaw('SUM(clicks) as ad_spend_clicks')
            ->selectRaw('SUM(impressions) as ad_spend_impressions')
            ->groupBy(['report_date', 'project_code', DB::raw('UPPER(COALESCE(country, ""))')])
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $country = $this->normalizeCountry((string) ($row->country ?? ''));
            $key = $this->makeDimensionKey((string) $row->report_date, (string) $row->project_code, $country);
            $result[$key] = [
                'ad_spend_cost' => $this->decimal($row->ad_spend_cost),
                'ad_spend_clicks' => (int) ($row->ad_spend_clicks ?? 0),
                'ad_spend_impressions' => (int) ($row->ad_spend_impressions ?? 0),
            ];
        }

        return $result;
    }

    private function queryUserMetrics(string $date): array
    {
        $result = [];

        $projectCodesByAppId = DB::table('project_user_app_map')
            ->where('enabled', '=', 1)
            ->select('project_code', 'app_id')
            ->get()
            ->groupBy(function ($row) {
                return trim((string) ($row->app_id ?? ''));
            })
            ->map(function ($group) {
                return collect($group)
                    ->pluck('project_code')
                    ->map(fn ($v) => trim((string) $v))
                    ->filter(fn ($v) => $v !== '')
                    ->unique()
                    ->values()
                    ->all();
            })
            ->filter(fn ($codes, $appId) => $appId !== '' && !empty($codes));

        if ($projectCodesByAppId->isEmpty()) {
            return $result;
        }

        $activeRows = DB::table('v3_user_report_count as urc')
            ->join('project_user_app_map as puam', function ($join) {
                $join->on('puam.app_id', '=', 'urc.app_id')
                    ->where('puam.enabled', '=', 1);
            })
            ->where('urc.date', '=', $date)
            ->selectRaw('puam.project_code as project_code')
            ->selectRaw('UPPER(COALESCE(urc.client_country, "")) as country')
            ->selectRaw('COUNT(DISTINCT urc.user_id) as dau_users')
            ->groupBy('puam.project_code')
            ->groupByRaw('UPPER(COALESCE(urc.client_country, ""))')
            ->get();

        foreach ($activeRows as $row) {
            $country = $this->normalizeCountry((string) ($row->country ?? ''));
            $key = $this->makeDimensionKey($date, (string) $row->project_code, $country);
            $result[$key]['dau_users'] = (int) ($row->dau_users ?? 0);
            $result[$key]['new_users'] = (int) ($result[$key]['new_users'] ?? 0);
        }

        $startTs = strtotime($date . ' 00:00:00');
        $endTs = strtotime($date . ' 23:59:59') + 1;

        $newUsers = DB::table('v2_user as u')
            ->where('u.created_at', '>=', $startTs)
            ->where('u.created_at', '<', $endTs)
            ->select('u.id', 'u.register_metadata')
            ->get();

        if ($newUsers->isEmpty()) {
            return $result;
        }

        $fallbackUserIds = [];
        $parsedUsers = [];
        foreach ($newUsers as $user) {
            $uid = (int) ($user->id ?? 0);
            $meta = $this->parseJson((string) ($user->register_metadata ?? ''));
            $appId = '';
            $country = '';
            if (is_array($meta)) {
                $appId = trim((string) ($meta['app_id'] ?? ''));
                $country = $this->normalizeCountry((string) ($meta['country'] ?? ''));
            }
            if ($appId === '') {
                $fallbackUserIds[] = $uid;
            }

            $parsedUsers[$uid] = [
                'app_id' => $appId,
                'country' => $country,
            ];
        }

        $fallbackByUserId = [];
        if (!empty($fallbackUserIds)) {
            $fallbackRows = DB::table('v3_user_report_count')
                ->where('date', '=', $date)
                ->whereIn('user_id', $fallbackUserIds)
                ->whereNotNull('app_id')
                ->where('app_id', '!=', '')
                ->selectRaw('user_id')
                ->selectRaw('MAX(app_id) as app_id')
                ->selectRaw('UPPER(MAX(COALESCE(client_country, ""))) as country')
                ->groupBy('user_id')
                ->get();

            foreach ($fallbackRows as $row) {
                $fallbackByUserId[(int) $row->user_id] = [
                    'app_id' => trim((string) ($row->app_id ?? '')),
                    'country' => $this->normalizeCountry((string) ($row->country ?? '')),
                ];
            }
        }

        foreach ($parsedUsers as $uid => $userMeta) {
            $appId = $userMeta['app_id'];
            $country = $userMeta['country'];

            if ($appId === '' && isset($fallbackByUserId[$uid])) {
                $appId = $fallbackByUserId[$uid]['app_id'];
                if ($country === '') {
                    $country = $fallbackByUserId[$uid]['country'];
                }
            }

            if ($appId === '' || !$projectCodesByAppId->has($appId)) {
                continue;
            }

            foreach ($projectCodesByAppId->get($appId, []) as $projectCode) {
                $key = $this->makeDimensionKey($date, $projectCode, $country);
                $result[$key]['new_users'] = (int) ($result[$key]['new_users'] ?? 0) + 1;
                $result[$key]['dau_users'] = (int) ($result[$key]['dau_users'] ?? 0);
            }
        }

        return $result;
    }

    private function queryTrafficUsageMbByCountry(string $date, string $projectCode): array
    {
        $accountRelations = DB::table('project_traffic_platform_accounts')
            ->where('project_code', '=', $projectCode)
            ->where('enabled', '=', 1)
            ->select('traffic_platform_account_id', 'external_uid')
            ->get();

        if ($accountRelations->isEmpty()) {
            return [];
        }

        $grouped = $accountRelations->groupBy('traffic_platform_account_id');
        $countryUsageMb = [];

        foreach ($grouped as $accountId => $rows) {
            $uidList = collect($rows)
                ->pluck('external_uid')
                ->map(fn ($v) => trim((string) $v))
                ->filter(fn ($v) => $v !== '')
                ->unique()
                ->values()
                ->all();

            $hasAccountLevel = collect($rows)->contains(function ($row) {
                return trim((string) ($row->external_uid ?? '')) === '';
            });

            $snapshotQuery = DB::table('traffic_platform_daily_snapshots')
                ->where('stat_date', '=', $date)
                ->where('platform_account_id', '=', (int) $accountId);

            if (!$hasAccountLevel) {
                if (empty($uidList)) {
                    continue;
                }
                $snapshotQuery->whereIn('external_uid', $uidList);
            }

            $snapshotRows = $snapshotQuery
                ->selectRaw('COALESCE(external_uid, "") as external_uid')
                ->selectRaw('UPPER(COALESCE(geo, "")) as country')
                ->selectRaw('COALESCE(region, "") as region')
                ->selectRaw('MAX(total_mb) as max_total_mb')
                ->groupByRaw('COALESCE(external_uid, ""), UPPER(COALESCE(geo, "")), COALESCE(region, "")')
                ->get();

            if ($snapshotRows->isNotEmpty()) {
                foreach ($snapshotRows as $row) {
                    $country = $this->normalizeCountry((string) ($row->country ?? ''));
                    $countryUsageMb[$country] = ($countryUsageMb[$country] ?? 0.0) + $this->decimal($row->max_total_mb);
                }
                continue;
            }

            $usageQuery = DB::table('traffic_platform_usage_stat')
                ->where('stat_date', '=', $date)
                ->where('platform_account_id', '=', (int) $accountId);

            if (!$hasAccountLevel) {
                if (empty($uidList)) {
                    continue;
                }
                $usageQuery->whereIn('external_uid', $uidList);
            }

            $usageRows = $usageQuery
                ->selectRaw('COALESCE(external_uid, "") as external_uid')
                ->selectRaw('UPPER(COALESCE(geo, "")) as country')
                ->selectRaw('COALESCE(region, "") as region')
                ->selectRaw('MAX(traffic_mb) as max_traffic_mb')
                ->groupByRaw('COALESCE(external_uid, ""), UPPER(COALESCE(geo, "")), COALESCE(region, "")')
                ->get();

            foreach ($usageRows as $row) {
                $country = $this->normalizeCountry((string) ($row->country ?? ''));
                $countryUsageMb[$country] = ($countryUsageMb[$country] ?? 0.0) + $this->decimal($row->max_traffic_mb);
            }
        }

        return $countryUsageMb;
    }

    private function buildProjectCodeSetFromDimensionMaps(array $dimensionMaps): array
    {
        $set = [];
        foreach ($dimensionMaps as $map) {
            foreach (array_keys($map) as $key) {
                [, $projectCode] = $this->parseDimensionKey($key);
                if ($projectCode !== '') {
                    $set[$projectCode] = true;
                }
            }
        }

        return $set;
    }

    private function makeDimensionKey(string $date, string $projectCode, string $country): string
    {
        return trim($date) . "\t" . trim($projectCode) . "\t" . $this->normalizeCountry($country);
    }

    private function parseDimensionKey(string $key): array
    {
        $parts = explode("\t", $key, 3);
        return [
            trim((string) ($parts[0] ?? '')),
            trim((string) ($parts[1] ?? '')),
            $this->normalizeCountry((string) ($parts[2] ?? '')),
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

    private function parseJson(string $json)
    {
        if ($json === '') {
            return null;
        }

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
