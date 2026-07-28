<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UserAdValueReportService
{
    private const MICROS_PER_USD = 1000000;

    private const PROJECT_KEY_COHORT_AGES = [0, 1, 3, 7];

    public function __construct(private readonly CurrencyRateService $currencyRateService)
    {
    }

    /**
     * Aggregate user ad-value reports and maintain first-report cohorts.
     */
    public function aggregatePayloads(array $payloads): void
    {
        if (empty($payloads)) {
            return;
        }

        $firstReportCandidates = [];
        $hourlyGroups = [];
        $unknownCurrencies = [];

        foreach ($payloads as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            $metadata = $this->resolveMetadata($payload);
            $userId = $this->resolveUserId($payload);
            $appId = $this->normalizeString($metadata['app_id'] ?? ($payload['app_id'] ?? ''), 255);

            if ($userId <= 0 || $appId === '') {
                continue;
            }

            $reportAtMs = UserReportService::resolveReportAtMs($metadata);
            $time = Carbon::createFromTimestampMsUTC($reportAtMs)->setTimezone('Asia/Shanghai');
            $date = $time->toDateString();
            $hour = (int) $time->hour;
            $minute = (int) $time->minute;
            $platform = $this->normalizeString($metadata['platform'] ?? ($payload['platform'] ?? ''), 100);
            $appVersion = $this->normalizeString($metadata['app_version'] ?? ($payload['app_version'] ?? ''), 50);
            $country = $this->normalizeString($metadata['country'] ?? ($payload['client_country'] ?? ''), 16, true);

            $this->keepEarliestFirstReport($firstReportCandidates, [
                'user_id' => $userId,
                'app_id' => $appId,
                'first_report_date' => $date,
                'first_report_hour' => $hour,
                'first_report_minute' => $minute,
                'first_report_at_ms' => $reportAtMs,
                'platform' => $platform,
                'app_version' => $appVersion,
                'country' => $country,
            ]);

            $adValueReports = $this->extractAdValueReports($payload['ads_value_reports'] ?? null);
            if (empty($adValueReports)) {
                continue;
            }

            foreach ($adValueReports as $report) {
                $valueMicros = $this->normalizeValueMicros($report['value_micros'] ?? null);
                $currency = $this->normalizeString($report['currency'] ?? '', 8, true);
                if ($valueMicros === null || $currency === '') {
                    continue;
                }

                $rate = $this->currencyRateService->rateToUsd($currency, $date);
                if ($rate === null) {
                    $unknownCurrencies[$currency] = ($unknownCurrencies[$currency] ?? 0) + 1;
                    continue;
                }

                $valueMicrosUsd = (int) round($valueMicros * $rate);
                $key = implode('|', [$date, $hour, $userId, $appId, $appVersion, $platform, $country]);
                if (!isset($hourlyGroups[$key])) {
                    $hourlyGroups[$key] = [
                        'date' => $date,
                        'hour' => $hour,
                        'user_id' => $userId,
                        'app_id' => $appId,
                        'app_version' => $appVersion,
                        'platform' => $platform,
                        'country' => $country,
                        'value_micros_usd' => 0,
                        'ad_value_report_count' => 0,
                    ];
                }

                $hourlyGroups[$key]['value_micros_usd'] += $valueMicrosUsd;
                $hourlyGroups[$key]['ad_value_report_count'] += 1;
            }
        }

        $this->mergeHistoricalFirstReports($firstReportCandidates);
        $this->upsertFirstReportCandidates(array_values($firstReportCandidates));
        $this->upsertHourlyGroups(array_values($hourlyGroups));
        $this->logUnknownCurrencies($unknownCurrencies);
    }

    /**
     * Query USD ad-value contribution by day-N cohort age.
     */
    public function queryCohortValueComposition(string $dateFrom, ?string $dateTo = null, array $filters = []): array
    {
        $dateTo = $dateTo ?: $dateFrom;

        $query = DB::table('v3_user_ad_value_hourly as av')
            ->leftJoin('v3_user_app_first_report as fr', function ($join) {
                $join->on('fr.user_id', '=', 'av.user_id')
                    ->on('fr.app_id', '=', 'av.app_id');
            })
            ->whereBetween('av.date', [$dateFrom, $dateTo]);

        $this->applyCompositionFilters($query, $filters);

        $rows = $query
            ->selectRaw('av.date as value_date')
            ->selectRaw('fr.first_report_date as first_report_date')
            ->selectRaw('SUM(av.value_micros_usd) as value_micros_usd')
            ->selectRaw('COUNT(DISTINCT av.user_id) as user_count')
            ->groupBy('av.date', 'fr.first_report_date')
            ->get();

        $buckets = [];
        $totalMicros = 0;

        foreach ($rows as $row) {
            $valueMicros = (int) ($row->value_micros_usd ?? 0);
            $totalMicros += $valueMicros;

            $age = $this->resolveCohortAge($row->value_date ?? null, $row->first_report_date ?? null);
            $key = $age === null ? 'unknown' : 'day' . $age;
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'cohortAge' => $age,
                    'cohortKey' => $key,
                    'valueMicrosUsd' => 0,
                    'userCount' => 0,
                ];
            }

            $buckets[$key]['valueMicrosUsd'] += $valueMicros;
            $buckets[$key]['userCount'] += (int) ($row->user_count ?? 0);
        }

        uasort($buckets, function (array $a, array $b): int {
            if ($a['cohortAge'] === null) {
                return 1;
            }
            if ($b['cohortAge'] === null) {
                return -1;
            }

            return $a['cohortAge'] <=> $b['cohortAge'];
        });

        $formattedBuckets = array_values(array_map(function (array $bucket) use ($totalMicros): array {
            $bucket['valueUsd'] = $this->formatUsdFromMicros($bucket['valueMicrosUsd']);
            $bucket['ratio'] = $totalMicros > 0
                ? round($bucket['valueMicrosUsd'] / $totalMicros, 6)
                : 0.0;

            return $bucket;
        }, $buckets));

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'totalValueMicrosUsd' => $totalMicros,
            'totalValueUsd' => $this->formatUsdFromMicros($totalMicros),
            'buckets' => $formattedBuckets,
        ];
    }

    /**
     * Query one project's ad-value composition by every actual day-N cohort.
     */
    public function queryProjectCohortValueComposition(string $projectCode, string $date): array
    {
        $projectCode = trim($projectCode);

        $rows = DB::table('v3_user_ad_value_hourly as av')
            ->join('project_user_app_map as puam', function ($join) use ($projectCode) {
                $join->on('puam.app_id', '=', 'av.app_id')
                    ->where('puam.enabled', '=', 1)
                    ->where('puam.project_code', '=', $projectCode);
            })
            ->leftJoin('v3_user_app_first_report as fr', function ($join) {
                $join->on('fr.user_id', '=', 'av.user_id')
                    ->on('fr.app_id', '=', 'av.app_id');
            })
            ->where('av.date', '=', $date)
            ->selectRaw('av.user_id as user_id')
            ->selectRaw('av.date as value_date')
            ->selectRaw('fr.first_report_date as first_report_date')
            ->selectRaw('SUM(av.value_micros_usd) as value_micros_usd')
            ->groupBy('av.user_id', 'av.date', 'fr.first_report_date')
            ->get();

        $buckets = [];
        $keyBuckets = $this->emptyProjectKeyBuckets();
        $unknown = $this->emptyCompositionBucket('unknown', null);
        $totalMicros = 0;

        foreach ($rows as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $valueMicros = (int) ($row->value_micros_usd ?? 0);
            $totalMicros += $valueMicros;

            $age = $this->resolveCohortAge($row->value_date ?? null, $row->first_report_date ?? null);
            if ($age === null) {
                $this->addCompositionValue($unknown, $userId, $valueMicros);
                continue;
            }

            $cohortKey = 'day' . $age;
            if (!isset($buckets[$cohortKey])) {
                $buckets[$cohortKey] = $this->emptyCompositionBucket($cohortKey, $age);
            }
            $this->addCompositionValue($buckets[$cohortKey], $userId, $valueMicros);

            if (in_array($age, self::PROJECT_KEY_COHORT_AGES, true)) {
                $this->addCompositionValue($keyBuckets[$cohortKey], $userId, $valueMicros);
            } elseif ($age >= 14) {
                $this->addCompositionValue($keyBuckets['day14_plus'], $userId, $valueMicros);
            }
        }

        uasort($buckets, static fn (array $left, array $right): int => $left['cohortAge'] <=> $right['cohortAge']);

        return [
            'projectCode' => $projectCode,
            'date' => $date,
            'totalValueMicrosUsd' => $totalMicros,
            'totalValueUsd' => $this->formatUsdFromMicros($totalMicros),
            'keyBuckets' => array_values(array_map(
                fn (array $bucket): array => $this->formatCompositionBucket($bucket, $totalMicros),
                $keyBuckets
            )),
            'buckets' => array_values(array_map(
                fn (array $bucket): array => $this->formatCompositionBucket($bucket, $totalMicros),
                $buckets
            )),
            'unknown' => $this->formatCompositionBucket($unknown, $totalMicros),
        ];
    }

    /**
     * Query daily project ad-value totals split into same-day and retained users.
     */
    public function queryProjectDailyNewRetainedValueComposition(string $projectCode, string $dateFrom, string $dateTo): array
    {
        $projectCode = trim($projectCode);
        $dailyRows = $this->emptyDailyValueRows($dateFrom, $dateTo);

        $rows = DB::table('v3_user_ad_value_hourly as av')
            ->join('project_user_app_map as puam', function ($join) use ($projectCode) {
                $join->on('puam.app_id', '=', 'av.app_id')
                    ->where('puam.enabled', '=', 1)
                    ->where('puam.project_code', '=', $projectCode);
            })
            ->leftJoin('v3_user_app_first_report as fr', function ($join) {
                $join->on('fr.user_id', '=', 'av.user_id')
                    ->on('fr.app_id', '=', 'av.app_id');
            })
            ->whereBetween('av.date', [$dateFrom, $dateTo])
            ->selectRaw('av.date as value_date')
            ->selectRaw('fr.first_report_date as first_report_date')
            ->selectRaw('SUM(av.value_micros_usd) as value_micros_usd')
            ->groupBy('av.date', 'fr.first_report_date')
            ->get();

        $summary = $this->emptyDailyValueMetrics();

        foreach ($rows as $row) {
            $date = (string) ($row->value_date ?? '');
            if (!isset($dailyRows[$date])) {
                continue;
            }

            $valueMicros = (int) ($row->value_micros_usd ?? 0);
            $age = $this->resolveCohortAge($row->value_date ?? null, $row->first_report_date ?? null);
            $bucket = $age === 0 ? 'new' : ($age === null ? 'unknown' : 'retained');

            $this->addDailyValue($dailyRows[$date], $bucket, $valueMicros);
            $this->addDailyValue($summary, $bucket, $valueMicros);
        }

        return [
            'projectCode' => $projectCode,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'data' => array_values(array_map(
                fn (array $row): array => $this->formatDailyValueRow($row),
                $dailyRows
            )),
            'summary' => $this->formatDailyValueRow($summary, false),
        ];
    }

    private function emptyDailyValueRows(string $dateFrom, string $dateTo): array
    {
        $rows = [];
        $date = Carbon::parse($dateFrom)->startOfDay();
        $endDate = Carbon::parse($dateTo)->startOfDay();

        while ($date->lte($endDate)) {
            $dateKey = $date->toDateString();
            $rows[$dateKey] = array_merge(['date' => $dateKey], $this->emptyDailyValueMetrics());
            $date->addDay();
        }

        return $rows;
    }

    private function emptyDailyValueMetrics(): array
    {
        return [
            'totalValueMicrosUsd' => 0,
            'newUserValueMicrosUsd' => 0,
            'retainedUserValueMicrosUsd' => 0,
            'unknownValueMicrosUsd' => 0,
        ];
    }

    private function addDailyValue(array &$row, string $bucket, int $valueMicros): void
    {
        $row['totalValueMicrosUsd'] += $valueMicros;

        if ($bucket === 'new') {
            $row['newUserValueMicrosUsd'] += $valueMicros;
        } elseif ($bucket === 'retained') {
            $row['retainedUserValueMicrosUsd'] += $valueMicros;
        } else {
            $row['unknownValueMicrosUsd'] += $valueMicros;
        }
    }

    private function formatDailyValueRow(array $row, bool $includeDate = true): array
    {
        $totalMicros = (int) $row['totalValueMicrosUsd'];
        $formatted = [];

        if ($includeDate) {
            $formatted['date'] = (string) $row['date'];
        }

        $formatted['totalValueMicrosUsd'] = $totalMicros;
        $formatted['totalValueUsd'] = $this->formatUsdFromMicros($totalMicros);
        $formatted['newUserValueMicrosUsd'] = (int) $row['newUserValueMicrosUsd'];
        $formatted['newUserValueUsd'] = $this->formatUsdFromMicros((int) $row['newUserValueMicrosUsd']);
        $formatted['newUserRatio'] = $this->dailyValueRatio((int) $row['newUserValueMicrosUsd'], $totalMicros);
        $formatted['retainedUserValueMicrosUsd'] = (int) $row['retainedUserValueMicrosUsd'];
        $formatted['retainedUserValueUsd'] = $this->formatUsdFromMicros((int) $row['retainedUserValueMicrosUsd']);
        $formatted['retainedUserRatio'] = $this->dailyValueRatio((int) $row['retainedUserValueMicrosUsd'], $totalMicros);
        $formatted['unknownValueMicrosUsd'] = (int) $row['unknownValueMicrosUsd'];
        $formatted['unknownValueUsd'] = $this->formatUsdFromMicros((int) $row['unknownValueMicrosUsd']);
        $formatted['unknownRatio'] = $this->dailyValueRatio((int) $row['unknownValueMicrosUsd'], $totalMicros);

        return $formatted;
    }

    private function dailyValueRatio(int $valueMicros, int $totalMicros): float
    {
        return $totalMicros > 0 ? round($valueMicros / $totalMicros, 6) : 0.0;
    }

    private function emptyProjectKeyBuckets(): array
    {
        return [
            'day0' => $this->emptyCompositionBucket('day0', 0),
            'day1' => $this->emptyCompositionBucket('day1', 1),
            'day3' => $this->emptyCompositionBucket('day3', 3),
            'day7' => $this->emptyCompositionBucket('day7', 7),
            'day14_plus' => $this->emptyCompositionBucket('day14_plus', 14),
        ];
    }

    private function emptyCompositionBucket(string $cohortKey, ?int $cohortAge): array
    {
        return [
            'cohortKey' => $cohortKey,
            'cohortAge' => $cohortAge,
            'valueMicrosUsd' => 0,
            'userIds' => [],
        ];
    }

    private function addCompositionValue(array &$bucket, int $userId, int $valueMicros): void
    {
        $bucket['valueMicrosUsd'] += $valueMicros;
        if ($userId > 0) {
            $bucket['userIds'][$userId] = true;
        }
    }

    private function formatCompositionBucket(array $bucket, int $totalMicros): array
    {
        return [
            'cohortKey' => $bucket['cohortKey'],
            'cohortAge' => $bucket['cohortAge'],
            'valueMicrosUsd' => (int) $bucket['valueMicrosUsd'],
            'valueUsd' => $this->formatUsdFromMicros((int) $bucket['valueMicrosUsd']),
            'ratio' => $totalMicros > 0 ? round((int) $bucket['valueMicrosUsd'] / $totalMicros, 6) : 0.0,
            'userCount' => count($bucket['userIds']),
        ];
    }

    private function upsertHourlyGroups(array $groups): void
    {
        foreach ($groups as $group) {
            if ((int) ($group['value_micros_usd'] ?? 0) <= 0 && (int) ($group['ad_value_report_count'] ?? 0) <= 0) {
                continue;
            }

            $attributes = [
                'date' => $group['date'],
                'hour' => (int) $group['hour'],
                'user_id' => (int) $group['user_id'],
                'app_id' => (string) $group['app_id'],
                'app_version' => (string) $group['app_version'],
                'platform' => (string) $group['platform'],
                'country' => (string) $group['country'],
            ];

            $valueMicrosUsd = (int) $group['value_micros_usd'];
            $reportCount = (int) $group['ad_value_report_count'];
            $inserted = DB::table('v3_user_ad_value_hourly')->insertOrIgnore(array_merge($attributes, [
                'value_micros_usd' => $valueMicrosUsd,
                'value_usd' => $this->formatUsdFromMicros($valueMicrosUsd),
                'ad_value_report_count' => $reportCount,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            if ((int) $inserted === 0) {
                DB::table('v3_user_ad_value_hourly')
                    ->where($attributes)
                    ->update([
                        'value_micros_usd' => DB::raw('value_micros_usd + ' . $valueMicrosUsd),
                        'value_usd' => DB::raw('ROUND(value_usd + ' . $this->formatUsdFromMicros($valueMicrosUsd) . ', 6)'),
                        'ad_value_report_count' => DB::raw('ad_value_report_count + ' . $reportCount),
                        'updated_at' => now(),
                    ]);
            }

            $this->bumpCacheVersion('v3_user_ad_value_hourly', (string) $group['date'], (int) $group['hour']);
        }
    }

    private function upsertFirstReportCandidates(array $candidates): void
    {
        foreach ($candidates as $candidate) {
            $attributes = [
                'user_id' => (int) $candidate['user_id'],
                'app_id' => (string) $candidate['app_id'],
            ];

            $values = [
                'first_report_date' => $candidate['first_report_date'],
                'first_report_hour' => (int) $candidate['first_report_hour'],
                'first_report_minute' => (int) $candidate['first_report_minute'],
                'first_report_at_ms' => (int) $candidate['first_report_at_ms'],
                'platform' => (string) ($candidate['platform'] ?? ''),
                'app_version' => (string) ($candidate['app_version'] ?? ''),
                'country' => (string) ($candidate['country'] ?? ''),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $inserted = DB::table('v3_user_app_first_report')->insertOrIgnore(array_merge($attributes, $values));
            if ((int) $inserted !== 0) {
                continue;
            }

            DB::table('v3_user_app_first_report')
                ->where($attributes)
                ->where(function (Builder $query) use ($candidate) {
                    $query->where('first_report_date', '>', $candidate['first_report_date'])
                        ->orWhere(function (Builder $sameDateQuery) use ($candidate) {
                            $sameDateQuery->where('first_report_date', '=', $candidate['first_report_date'])
                                ->where('first_report_hour', '>', (int) $candidate['first_report_hour']);
                        })
                        ->orWhere(function (Builder $sameHourQuery) use ($candidate) {
                            $sameHourQuery->where('first_report_date', '=', $candidate['first_report_date'])
                                ->where('first_report_hour', '=', (int) $candidate['first_report_hour'])
                                ->where('first_report_minute', '>', (int) $candidate['first_report_minute']);
                        });
                })
                ->update($values);
        }
    }

    private function mergeHistoricalFirstReports(array &$candidates): void
    {
        if (empty($candidates) || !Schema::hasTable('v3_user_report_count')) {
            return;
        }

        $usersByApp = [];
        foreach ($candidates as $candidate) {
            $usersByApp[(string) $candidate['app_id']][] = (int) $candidate['user_id'];
        }

        foreach ($usersByApp as $appId => $userIds) {
            $userIds = array_values(array_unique($userIds));
            foreach (array_chunk($userIds, 1000) as $chunk) {
                $rows = DB::table('v3_user_report_count')
                    ->where('app_id', $appId)
                    ->whereIn('user_id', $chunk)
                    ->select([
                        'user_id',
                        'app_id',
                        'date',
                        'hour',
                        'minute',
                        'platform',
                        'app_version',
                        'client_country',
                    ])
                    ->orderBy('user_id')
                    ->orderBy('app_id')
                    ->orderBy('date')
                    ->orderBy('hour')
                    ->orderBy('minute')
                    ->get();

                $seen = [];
                foreach ($rows as $row) {
                    $rowAppId = $this->normalizeString($row->app_id ?? '', 255);
                    $key = $this->firstReportKey((int) $row->user_id, $rowAppId);
                    if (isset($seen[$key]) || $rowAppId === '') {
                        continue;
                    }

                    $seen[$key] = true;
                    $date = (string) $row->date;
                    $hour = (int) $row->hour;
                    $minute = (int) $row->minute;
                    $time = Carbon::parse(sprintf('%s %02d:%02d:00', $date, $hour, $minute), 'Asia/Shanghai');

                    $this->keepEarliestFirstReport($candidates, [
                        'user_id' => (int) $row->user_id,
                        'app_id' => $rowAppId,
                        'first_report_date' => $date,
                        'first_report_hour' => $hour,
                        'first_report_minute' => $minute,
                        'first_report_at_ms' => $time->copy()->utc()->getTimestampMs(),
                        'platform' => $this->normalizeString($row->platform ?? '', 100),
                        'app_version' => $this->normalizeString($row->app_version ?? '', 50),
                        'country' => $this->normalizeString($row->client_country ?? '', 16, true),
                    ]);
                }
            }
        }
    }

    private function keepEarliestFirstReport(array &$candidates, array $candidate): void
    {
        $key = $this->firstReportKey((int) $candidate['user_id'], (string) $candidate['app_id']);
        if (!isset($candidates[$key]) || $this->isEarlierFirstReport($candidate, $candidates[$key])) {
            $candidates[$key] = $candidate;
        }
    }

    private function isEarlierFirstReport(array $left, array $right): bool
    {
        if ((string) $left['first_report_date'] !== (string) $right['first_report_date']) {
            return (string) $left['first_report_date'] < (string) $right['first_report_date'];
        }

        if ((int) $left['first_report_hour'] !== (int) $right['first_report_hour']) {
            return (int) $left['first_report_hour'] < (int) $right['first_report_hour'];
        }

        return (int) $left['first_report_minute'] < (int) $right['first_report_minute'];
    }

    private function firstReportKey(int $userId, string $appId): string
    {
        return $userId . '|' . $appId;
    }

    private function resolveCohortAge($valueDate, $firstReportDate): ?int
    {
        if ($valueDate === null || $firstReportDate === null || $firstReportDate === '') {
            return null;
        }

        try {
            $age = (int) Carbon::parse((string) $firstReportDate)
                ->startOfDay()
                ->diffInDays(Carbon::parse((string) $valueDate)->startOfDay(), false);
        } catch (\Throwable) {
            return null;
        }

        return $age < 0 ? null : $age;
    }

    private function applyCompositionFilters(Builder $query, array $filters): void
    {
        $map = [
            'appId' => 'app_id',
            'app_id' => 'app_id',
            'platform' => 'platform',
            'appVersion' => 'app_version',
            'app_version' => 'app_version',
            'country' => 'country',
        ];

        foreach ($map as $key => $column) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
                continue;
            }

            $maxLength = match ($column) {
                'app_id' => 255,
                'app_version' => 50,
                'country' => 16,
                default => 100,
            };
            $value = $column === 'country'
                ? $this->normalizeString($filters[$key], 16, true)
                : $this->normalizeString($filters[$key], $maxLength);
            $query->where('av.' . $column, $value);
        }
    }

    private function resolveMetadata(array $payload): array
    {
        $metadata = $payload['metadata'] ?? null;
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function resolveUserId(array $payload): int
    {
        return (int) ($payload['user_id'] ?? ($payload['userId'] ?? 0));
    }

    private function extractAdValueReports($reports): array
    {
        if (is_string($reports)) {
            $decoded = json_decode($reports, true);
            $reports = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($reports)) {
            return [];
        }

        return array_values(array_filter($reports, static fn($report) => is_array($report)));
    }

    private function normalizeValueMicros($value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        if (is_float($value) && floor($value) === $value && $value >= 0) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeString($value, int $maxLength, bool $uppercase = false): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($uppercase) {
            $normalized = strtoupper($normalized);
        }

        return substr($normalized, 0, $maxLength);
    }

    private function formatUsdFromMicros(int $valueMicros): string
    {
        return number_format($valueMicros / self::MICROS_PER_USD, 6, '.', '');
    }

    private function logUnknownCurrencies(array $unknownCurrencies): void
    {
        if (empty($unknownCurrencies)) {
            return;
        }

        Log::warning('Skipped user ad value reports with unsupported currencies', [
            'currencies' => $unknownCurrencies,
        ]);
    }

    private function bumpCacheVersion(string $table, string $date, int $hour): void
    {
        $versionKey = sprintf('user_report:qv:%s:%s:%02d', $table, $date, $hour);
        Cache::increment($versionKey);
        Cache::put($versionKey, (int) Cache::get($versionKey, 1), now()->addDays(7));
    }
}
