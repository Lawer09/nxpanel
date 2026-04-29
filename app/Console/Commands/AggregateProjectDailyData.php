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
            ->selectRaw('UPPER(COALESCE(ar.country_code, "")) as ad_country')
            ->selectRaw('SUM(ar.estimated_earnings) as revenue')
            ->selectRaw('SUM(ar.ad_requests) as ad_requests')
            ->selectRaw('SUM(ar.matched_requests) as matched_requests')
            ->selectRaw('SUM(ar.impressions) as impressions')
            ->selectRaw('SUM(ar.clicks) as clicks')
            ->groupBy(['ar.report_date', 'p.project_code', 'ar.country_code'])
            ->get();

        if ($rows->isEmpty()) {
            $this->info("No revenue rows for {$date}");
            return;
        }

        foreach ($rows as $row) {
            $projectCode = (string) $row->project_code;
            $adCountry = strtoupper(trim((string) ($row->ad_country ?? '')));

            $userMetrics = $this->buildUserMetrics($date, $projectCode, $adCountry);
            $adSpendCost = $this->queryAdSpendCost($date, $projectCode, $adCountry);
            $trafficUsageGb = $this->queryTrafficUsageGb($date, $projectCode);
            $trafficCost = round($trafficUsageGb * 0.16, 6);

            $revenue = $this->decimal($row->revenue);
            $adRequests = (int) ($row->ad_requests ?? 0);
            $matchedRequests = (int) ($row->matched_requests ?? 0);
            $impressions = (int) ($row->impressions ?? 0);
            $clicks = (int) ($row->clicks ?? 0);

            $ecpm = $this->safeRatio($revenue * 1000, $impressions);
            $ctr = $this->safeRatio($clicks * 100, $impressions);
            $matchRate = $this->safeRatio($matchedRequests * 100, $adRequests);
            $showRate = $this->safeRatio($impressions * 100, $matchedRequests);

            $grossProfit = round($revenue - $adSpendCost - $trafficCost, 6);
            $totalCost = round($adSpendCost + $trafficCost, 6);
            $roi = $this->safeRatio($grossProfit, $totalCost);
            $cpi = $this->safeRatio($adSpendCost, $userMetrics['report_new_users']);

            DB::table('project_daily_aggregates')->updateOrInsert(
                [
                    'report_date' => $date,
                    'project_code' => $projectCode,
                    'ad_country' => $adCountry,
                ],
                [
                    'spend_country' => $adCountry,
                    'user_country' => $userMetrics['user_country'],
                    'report_new_users' => $userMetrics['report_new_users'],
                    'dau_users' => $userMetrics['dau_users'],
                    'register_new_users' => $userMetrics['register_new_users'],
                    'revenue' => $revenue,
                    'ad_requests' => $adRequests,
                    'matched_requests' => $matchedRequests,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ecpm' => $ecpm,
                    'ctr' => $ctr,
                    'match_rate' => $matchRate,
                    'show_rate' => $showRate,
                    'ad_spend_cost' => $adSpendCost,
                    'traffic_usage_gb' => round($trafficUsageGb, 6),
                    'traffic_cost' => $trafficCost,
                    'gross_profit' => $grossProfit,
                    'roi' => $roi,
                    'cpi' => $cpi,
                    'fb_ecpm' => $ecpm,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    private function buildUserMetrics(string $date, string $projectCode, string $adCountry): array
    {
        $targetUserCountry = $this->normalizeCountry($adCountry);

        $appStoreIds = DB::table('project_user_app_map')
            ->where('project_code', '=', $projectCode)
            ->where('enabled', '=', 1)
            ->pluck('app_id')
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values()
            ->all();

        if (empty($appStoreIds)) {
            return [
                'user_country' => $targetUserCountry,
                'dau_users' => 0,
                'report_new_users' => 0,
                'register_new_users' => 0,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($appStoreIds), '?'));
        $metaAppIdExpr = "JSON_UNQUOTE(JSON_EXTRACT(u.register_metadata, '$.app_id'))";

        $users = DB::table('v2_user as u')
            ->where(function ($query) use ($appStoreIds, $placeholders, $metaAppIdExpr) {
                $query->whereRaw("{$metaAppIdExpr} in ({$placeholders})", $appStoreIds)
                    ->orWhere(function ($fallback) use ($appStoreIds, $metaAppIdExpr) {
                        $fallback->where(function ($metaEmpty) use ($metaAppIdExpr) {
                            $metaEmpty->whereNull('u.register_metadata')
                                ->orWhereRaw("COALESCE({$metaAppIdExpr}, '') = ''");
                        })
                        ->whereExists(function ($sub) use ($appStoreIds) {
                            $sub->select(DB::raw(1))
                                ->from('v3_user_report_count as urc')
                                ->whereColumn('urc.user_id', 'u.id')
                                ->whereNotNull('urc.app_id')
                                ->where('urc.app_id', '!=', '')
                                ->whereIn('urc.app_id', $appStoreIds);
                        });
                    });
            })
            ->select('u.id', 'u.created_at', 'u.register_metadata')
            ->get();

        if ($users->isEmpty()) {
            return [
                'user_country' => $targetUserCountry,
                'dau_users' => 0,
                'report_new_users' => 0,
                'register_new_users' => 0,
            ];
        }

        $uids = $users->pluck('id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        $firstReportDateByUser = DB::table('v3_user_report_count')
            ->whereIn('user_id', $uids)
            ->selectRaw('user_id, MIN(date) as first_date')
            ->groupBy('user_id')
            ->pluck('first_date', 'user_id');

        $usersById = $users->keyBy('id');

        $activeUserIds = DB::table('v3_user_report_count')
            ->where('date', '=', $date)
            ->whereIn('user_id', $uids)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();

        $activeSet = array_flip($activeUserIds);

        $startTs = strtotime($date . ' 00:00:00');
        $endTs = strtotime($date . ' 23:59:59') + 1;

        $dau = 0;
        $reportNew = 0;
        $registerNew = 0;

        foreach ($usersById as $uid => $user) {
            $uid = (int) $uid;
            $meta = $this->parseJson((string) ($user->register_metadata ?? ''));

            $metaCountry = '';
            if (is_array($meta)) {
                $metaCountry = strtoupper(trim((string) ($meta['country'] ?? '')));
            }

            $userCountry = $this->normalizeCountry($metaCountry);

            if ($targetUserCountry !== $userCountry) {
                continue;
            }

            if (isset($activeSet[$uid])) {
                $dau++;
            }

            $firstDate = (string) ($firstReportDateByUser[$uid] ?? '');
            if ($firstDate !== '' && $firstDate === $date) {
                $reportNew++;
            }

            $createdAt = (int) ($user->created_at ?? 0);
            if ($createdAt >= $startTs && $createdAt < $endTs) {
                $registerNew++;
            }
        }

        return [
            'user_country' => $targetUserCountry,
            'dau_users' => $dau,
            'report_new_users' => $reportNew,
            'register_new_users' => $registerNew,
        ];
    }

    private function normalizeCountry(?string $country): string
    {
        $value = strtoupper(trim((string) ($country ?? '')));
        return $value === '' ? 'OO' : $value;
    }

    private function queryAdSpendCost(string $date, string $projectCode, string $adCountry): float
    {
        $query = DB::table('ad_spend_platform_daily_reports')
            ->where('report_date', '=', $date)
            ->where('project_code', '=', $projectCode)
            ->whereRaw('UPPER(COALESCE(country, "")) = ?', [$adCountry]);

        return $this->decimal($query->sum('spend'));
    }

    private function queryTrafficUsageGb(string $date, string $projectCode): float
    {
        $accountRelations = DB::table('project_traffic_platform_accounts')
            ->where('project_code', '=', $projectCode)
            ->where('enabled', '=', 1)
            ->select('traffic_platform_account_id', 'external_uid')
            ->get();

        if ($accountRelations->isEmpty()) {
            return 0;
        }

        $grouped = $accountRelations->groupBy('traffic_platform_account_id');
        $sum = 0.0;

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
                ->selectRaw('COALESCE(geo, "") as geo')
                ->selectRaw('COALESCE(region, "") as region')
                ->selectRaw('MAX(total_mb) as max_total_mb')
                ->groupByRaw('COALESCE(external_uid, ""), COALESCE(geo, ""), COALESCE(region, "")')
                ->get();

            if ($snapshotRows->isNotEmpty()) {
                $sum += $this->decimal($snapshotRows->sum('max_total_mb')) / 1024;
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
                ->selectRaw('COALESCE(geo, "") as geo')
                ->selectRaw('COALESCE(region, "") as region')
                ->selectRaw('MAX(traffic_mb) as max_traffic_mb')
                ->groupByRaw('COALESCE(external_uid, ""), COALESCE(geo, ""), COALESCE(region, "")')
                ->get();

            $sum += $this->decimal($usageRows->sum('max_traffic_mb')) / 1024;
        }

        return $sum;
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
