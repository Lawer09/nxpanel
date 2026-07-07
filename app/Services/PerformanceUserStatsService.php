<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PerformanceUserStatsService
{
    private const RETENTION_DAYS = [1, 3, 7, 14, 30];

    public function __construct(private readonly UserService $userService)
    {
    }

    /**
     * Build retention cohorts using one cohort query and one retained-user aggregate query.
     */
    public function retention(Request $request): array
    {
        $dateFrom = $request->input('dateFrom', now()->subDays(30)->toDateString());
        $dateTo = $request->input('dateTo', now()->subDay()->toDateString());
        $today = now()->toDateString();

        $cohortQuery = DB::table('v3_user_report_count')
            ->selectRaw('date, COUNT(DISTINCT user_id) as active_users')
            ->whereBetween('date', [$dateFrom, $dateTo]);

        $this->applyUserReportFilters($cohortQuery, $request, null, ['appId', 'platform']);

        $cohorts = $cohortQuery
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $targetDates = [];
        foreach ($cohorts as $cohort) {
            foreach (self::RETENTION_DAYS as $day) {
                $targetDate = Carbon::parse($cohort->date)->addDays($day)->toDateString();
                if ($targetDate <= $today) {
                    $targetDates[$targetDate] = $targetDate;
                }
            }
        }

        $retainedMap = [];
        if ($cohorts->isNotEmpty() && $targetDates !== []) {
            $retainedQuery = DB::table('v3_user_report_count as a')
                ->join('v3_user_report_count as b', 'a.user_id', '=', 'b.user_id')
                ->selectRaw('a.date as cohort_date, b.date as target_date, COUNT(DISTINCT a.user_id) as retained_users')
                ->whereBetween('a.date', [$dateFrom, $dateTo])
                ->whereIn('b.date', array_values($targetDates));
            $this->whereRetentionDayDiff($retainedQuery);

            $this->applyUserReportFilters($retainedQuery, $request, 'a', ['appId', 'platform']);

            $retainedRows = $retainedQuery
                ->groupBy('a.date', 'b.date')
                ->get();

            foreach ($retainedRows as $row) {
                $retainedMap[$row->cohort_date . '|' . $row->target_date] = (int) $row->retained_users;
            }
        }

        $data = [];
        foreach ($cohorts as $cohort) {
            $activeUsers = (int) $cohort->active_users;
            $row = [
                'date' => $cohort->date,
                'active_users' => $activeUsers,
                'retention' => [],
            ];

            foreach (self::RETENTION_DAYS as $day) {
                $targetDate = Carbon::parse($cohort->date)->addDays($day)->toDateString();
                if ($targetDate > $today) {
                    $row['retention']["day_{$day}"] = null;
                    continue;
                }

                $retained = $retainedMap[$cohort->date . '|' . $targetDate] ?? 0;
                $row['retention']["day_{$day}"] = [
                    'count' => $retained,
                    'rate' => $activeUsers > 0 ? round($retained / $activeUsers * 100, 2) : 0,
                ];
            }

            $data[] = $row;
        }

        return [
            'data' => $data,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'retentionDays' => self::RETENTION_DAYS,
        ];
    }

    /**
     * Query active user trends and first-report users without full-table MIN(date) aggregation.
     */
    public function activeUsers(Request $request): array
    {
        $dateFrom = $request->input('dateFrom', now()->subDays(30)->toDateString());
        $dateTo = $request->input('dateTo', now()->toDateString());
        $granularity = $request->input('granularity', 'day');

        $baseQuery = DB::table('v3_user_report_count')
            ->whereBetween('date', [$dateFrom, $dateTo]);
        $this->applyUserReportFilters($baseQuery, $request, null, ['appId', 'platform']);

        $periodExpr = $this->periodExpression($granularity, 'date');
        $data = (clone $baseQuery)
            ->selectRaw("{$periodExpr} as period, MIN(date) as period_start, MAX(date) as period_end, COUNT(DISTINCT user_id) as active_users, SUM(report_count) as total_reports")
            ->groupByRaw($periodExpr)
            ->orderBy('period')
            ->get();

        $cacheKey = sprintf(
            'perf:active_users:new_users:v2:%s:%s:%s:%s:%s',
            $granularity,
            $dateFrom,
            $dateTo,
            $request->input('appId', ''),
            $request->input('platform', '')
        );

        $newMap = Cache::remember($cacheKey, 300, function () use ($request, $dateFrom, $dateTo, $granularity) {
            return $this->firstReportUsersByPeriod($request, $dateFrom, $dateTo, $granularity);
        });

        $regMap = $this->userService->getNewUsersByDateRange(
            $dateFrom,
            $dateTo,
            $granularity,
            [
                'appId' => $request->input('appId'),
                'platform' => $request->input('platform'),
            ]
        );

        $data = $data->map(function ($row) use ($newMap, $regMap) {
            $key = (string) $row->period;
            $row->new_users = $newMap[$key] ?? 0;
            $row->reg_users = $regMap[$key] ?? 0;

            return $row;
        });

        return [
            'data' => $data,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'granularity' => $granularity,
        ];
    }

    /**
     * Query the latest 24 hourly buckets using date/hour predicates that can use indexes.
     */
    public function userHourlyStats(Request $request): array
    {
        $now = now()->startOfHour();
        $start = (clone $now)->subHours(23);

        $activeRows = DB::table('v3_user_report_count')
            ->selectRaw('date, hour, COUNT(DISTINCT user_id) as active_users')
            ->where(function ($query) use ($start, $now) {
                $this->whereDateHourBetween($query, $start, $now);
            });
        $this->applyUserReportFilters($activeRows, $request, null, ['appId', 'platform', 'appVersion', 'clientCountry']);
        $activeRows = $activeRows
            ->groupBy('date', 'hour')
            ->get();

        $newRows = DB::table('v3_user_report_count as c')
            ->selectRaw('c.date as date, c.hour as hour, COUNT(DISTINCT c.user_id) as new_users')
            ->where(function ($query) use ($start, $now) {
                $this->whereDateHourBetween($query, $start, $now, 'c');
            });
        $this->applyUserReportFilters($newRows, $request, 'c', ['appId', 'platform', 'appVersion', 'clientCountry']);
        $newRows = $newRows
            ->whereNotExists(function ($query) use ($request) {
                $query->selectRaw('1')
                    ->from('v3_user_report_count as p')
                    ->whereColumn('p.user_id', 'c.user_id')
                    ->where(function ($timeQuery) {
                        $timeQuery->whereColumn('p.date', '<', 'c.date')
                            ->orWhere(function ($sameDateQuery) {
                                $sameDateQuery->whereColumn('p.date', 'c.date')
                                    ->whereColumn('p.hour', '<', 'c.hour');
                            })
                            ->orWhere(function ($sameHourQuery) {
                                $sameHourQuery->whereColumn('p.date', 'c.date')
                                    ->whereColumn('p.hour', 'c.hour')
                                    ->whereColumn('p.minute', '<', 'c.minute');
                            });
                    });
                $this->applyUserReportFilters($query, $request, 'p', ['appId', 'platform', 'appVersion', 'clientCountry']);
            })
            ->groupBy('c.date', 'c.hour')
            ->get();

        $activeMap = $this->mapHourlyRows($activeRows, 'active_users');
        $newMap = $this->mapHourlyRows($newRows, 'new_users');

        $items = [];
        $cursor = (clone $start);
        while ($cursor <= $now) {
            $date = $cursor->toDateString();
            $hour = (int) $cursor->format('H');
            $key = $this->hourKey($date, $hour);

            $items[] = [
                'time' => $cursor->format('Y-m-d H:00'),
                'new_users' => $newMap[$key] ?? 0,
                'active_users' => $activeMap[$key] ?? 0,
            ];

            $cursor->addHour();
        }

        return [
            'data' => $items,
            'start' => $start->format('Y-m-d H:00'),
            'end' => $now->format('Y-m-d H:00'),
        ];
    }

    private function firstReportUsersByPeriod(Request $request, string $dateFrom, string $dateTo, string $granularity): array
    {
        $periodExpr = $this->periodExpression($granularity, 'c.date');
        $query = DB::table('v3_user_report_count as c')
            ->selectRaw("{$periodExpr} as period, COUNT(DISTINCT c.user_id) as new_users")
            ->whereBetween('c.date', [$dateFrom, $dateTo]);

        $this->applyUserReportFilters($query, $request, 'c', ['appId', 'platform']);

        $query->whereNotExists(function ($subQuery) use ($request) {
            $subQuery->selectRaw('1')
                ->from('v3_user_report_count as p')
                ->whereColumn('p.user_id', 'c.user_id')
                ->whereColumn('p.date', '<', 'c.date');
            $this->applyUserReportFilters($subQuery, $request, 'p', ['appId', 'platform']);
        });

        return $query
            ->groupByRaw($periodExpr)
            ->orderBy('period')
            ->get()
            ->mapWithKeys(fn($row) => [(string) $row->period => (int) $row->new_users])
            ->toArray();
    }

    private function applyUserReportFilters(Builder $query, Request $request, ?string $alias, array $filters): void
    {
        $prefix = $alias ? "{$alias}." : '';
        $map = [
            'appId' => 'app_id',
            'platform' => 'platform',
            'appVersion' => 'app_version',
            'clientCountry' => 'client_country',
        ];

        foreach ($filters as $filter) {
            if ($request->filled($filter)) {
                $query->where($prefix . $map[$filter], $request->input($filter));
            }
        }
    }

    private function periodExpression(string $granularity, string $column): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($granularity) {
            'week' => $driver === 'sqlite'
                ? "strftime('%Y%W', {$column})"
                : "YEARWEEK({$column}, 1)",
            'month' => $driver === 'sqlite'
                ? "strftime('%Y-%m', {$column})"
                : "DATE_FORMAT({$column}, '%Y-%m')",
            default => $column,
        };
    }

    private function whereDateHourBetween(Builder $query, Carbon $start, Carbon $end, ?string $alias = null): void
    {
        $dateColumn = $alias ? "{$alias}.date" : 'date';
        $hourColumn = $alias ? "{$alias}.hour" : 'hour';
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $startHour = (int) $start->format('H');
        $endHour = (int) $end->format('H');

        if ($startDate === $endDate) {
            $query->where($dateColumn, $startDate)
                ->whereBetween($hourColumn, [$startHour, $endHour]);

            return;
        }

        $query->where(function ($timeQuery) use ($dateColumn, $hourColumn, $startDate, $endDate, $startHour, $endHour) {
            $timeQuery->where(function ($startQuery) use ($dateColumn, $hourColumn, $startDate, $startHour) {
                $startQuery->where($dateColumn, $startDate)
                    ->where($hourColumn, '>=', $startHour);
            })->orWhere(function ($endQuery) use ($dateColumn, $hourColumn, $endDate, $endHour) {
                $endQuery->where($dateColumn, $endDate)
                    ->where($hourColumn, '<=', $endHour);
            });

            $middleStart = Carbon::parse($startDate)->addDay()->toDateString();
            $middleEnd = Carbon::parse($endDate)->subDay()->toDateString();
            if ($middleStart <= $middleEnd) {
                $timeQuery->orWhereBetween($dateColumn, [$middleStart, $middleEnd]);
            }
        });
    }

    private function whereRetentionDayDiff(Builder $query): void
    {
        $driver = DB::connection()->getDriverName();
        $placeholders = implode(',', array_fill(0, count(self::RETENTION_DAYS), '?'));

        if ($driver === 'sqlite') {
            $query->whereRaw("CAST(julianday(b.date) - julianday(a.date) AS INTEGER) IN ({$placeholders})", self::RETENTION_DAYS);

            return;
        }

        $query->whereRaw("DATEDIFF(b.date, a.date) IN ({$placeholders})", self::RETENTION_DAYS);
    }

    private function mapHourlyRows(Collection $rows, string $field): array
    {
        return $rows
            ->mapWithKeys(fn($row) => [$this->hourKey((string) $row->date, (int) $row->hour) => (int) $row->{$field}])
            ->toArray();
    }

    private function hourKey(string $date, int $hour): string
    {
        return $date . '_' . str_pad((string) $hour, 2, '0', STR_PAD_LEFT);
    }
}
