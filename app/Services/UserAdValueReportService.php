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
