<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CurrencyRateService
{
    public const BASE_CURRENCY = 'USD';
    public const REDIS_KEY_PREFIX = 'currency_rates:to_usd:';

    private static array $memoryRates = [];

    /**
     * Resolve a currency-to-USD rate from memory, Redis, or daily DB snapshots.
     */
    public function rateToUsd(string $currency, ?string $date = null): ?float
    {
        $currency = $this->normalizeCurrency($currency);
        if ($currency === '') {
            return null;
        }

        if ($currency === self::BASE_CURRENCY) {
            return 1.0;
        }

        $rateDate = $this->normalizeDate($date);
        $memoryRate = $this->readMemoryRate($rateDate, $currency);
        if ($memoryRate !== null) {
            return $memoryRate;
        }

        $redisRate = $this->readRedisRate($rateDate, $currency);
        if ($redisRate !== null) {
            $this->rememberRate($rateDate, $currency, $redisRate);

            return $redisRate;
        }

        $databaseRate = $this->readDatabaseRate($rateDate, $currency);
        if ($databaseRate !== null) {
            $this->rememberRate($rateDate, $currency, $databaseRate);
            $this->publishRatesToRedis($rateDate, [$currency => $databaseRate]);

            return $databaseRate;
        }

        $fallback = $this->readFallbackDatabaseRate($rateDate, $currency);
        if ($fallback !== null) {
            Log::warning('Currency rate fallback snapshot used', [
                'currency' => $currency,
                'requested_date' => $rateDate,
                'fallback_date' => $fallback['date'],
            ]);

            return $fallback['rate'];
        }

        $overrideRate = $this->overrideRate($currency);
        if ($overrideRate !== null) {
            Log::warning('Currency rate override used without daily snapshot', [
                'currency' => $currency,
                'date' => $rateDate,
            ]);
            $this->rememberRate($rateDate, $currency, $overrideRate);

            return $overrideRate;
        }

        return null;
    }

    /**
     * Store a daily snapshot and warm both Redis and in-process caches.
     */
    public function storeRates(string $date, array $rates, string $source, array $rawPayload = []): array
    {
        $rateDate = $this->normalizeDate($date);
        $normalizedRates = $this->normalizeRates(array_merge([self::BASE_CURRENCY => 1.0], $rates));
        if (empty($normalizedRates)) {
            return [];
        }

        $now = now();
        $rawPayloadJson = empty($rawPayload) ? null : json_encode($rawPayload, JSON_UNESCAPED_UNICODE);
        $rows = [];

        foreach ($normalizedRates as $currency => $rate) {
            $rows[] = [
                'rate_date' => $rateDate,
                'base_currency' => self::BASE_CURRENCY,
                'currency_code' => $currency,
                'rate_to_usd' => self::formatRate($rate),
                'source' => substr($source, 0, 64),
                'synced_at' => $now,
                'raw_payload' => $rawPayloadJson,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('currency_rates_daily')->upsert(
            $rows,
            ['rate_date', 'currency_code'],
            ['base_currency', 'rate_to_usd', 'source', 'synced_at', 'raw_payload', 'updated_at']
        );

        $this->publishRatesToRedis($rateDate, $normalizedRates);
        foreach ($normalizedRates as $currency => $rate) {
            $this->rememberRate($rateDate, $currency, $rate);
        }

        return $normalizedRates;
    }

    /**
     * Return configured default sync currencies, always including USD.
     */
    public function defaultCurrencies(): array
    {
        return $this->normalizeCurrencies(config('currency_rate.default_currencies', []));
    }

    /**
     * Read stored rates for one date.
     */
    public function storedRatesForDate(string $date, array $currencies): array
    {
        $rateDate = $this->normalizeDate($date);
        $currencies = $this->normalizeCurrencies($currencies);
        if (empty($currencies)) {
            return [];
        }

        try {
            $rows = DB::table('currency_rates_daily')
                ->where('rate_date', $rateDate)
                ->whereIn('currency_code', $currencies)
                ->get(['currency_code', 'rate_to_usd']);
        } catch (\Throwable $e) {
            Log::warning('Read currency rate snapshots failed', [
                'date' => $rateDate,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $rates = [];
        foreach ($rows as $row) {
            $rate = $this->normalizeRate($row->rate_to_usd ?? null);
            if ($rate === null) {
                continue;
            }

            $rates[$this->normalizeCurrency($row->currency_code ?? '')] = $rate;
        }

        return $rates;
    }

    /**
     * Publish a date snapshot to Redis without changing the DB source of truth.
     */
    public function publishRatesToRedis(string $date, array $rates): void
    {
        if (!$this->redisEnabled()) {
            return;
        }

        $rateDate = $this->normalizeDate($date);
        $normalizedRates = $this->normalizeRates($rates);
        if (empty($normalizedRates)) {
            return;
        }

        try {
            Redis::pipeline(function ($pipe) use ($rateDate, $normalizedRates) {
                $key = $this->redisKey($rateDate);
                $pipe->hMSet($key, array_map(static fn(float $rate): string => self::formatRate($rate), $normalizedRates));
                $pipe->expire($key, $this->redisTtlSeconds());
            });
        } catch (\Throwable $e) {
            Log::warning('Publish currency rates to Redis failed', [
                'date' => $rateDate,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return configured emergency overrides for the requested currencies.
     */
    public function overrideRates(array $currencies): array
    {
        $currencies = $this->normalizeCurrencies($currencies);
        if (empty($currencies)) {
            return [];
        }

        $overrides = [];
        foreach ($currencies as $currency) {
            if ($currency === self::BASE_CURRENCY) {
                continue;
            }

            $rate = $this->overrideRate($currency);
            if ($rate !== null) {
                $overrides[$currency] = $rate;
            }
        }

        return $overrides;
    }

    /**
     * Clear static memory cache for tests and long-running maintenance flows.
     */
    public static function clearMemoryCache(): void
    {
        self::$memoryRates = [];
    }

    private function readMemoryRate(string $date, string $currency): ?float
    {
        return self::$memoryRates[$date][$currency] ?? null;
    }

    private function rememberRate(string $date, string $currency, float $rate): void
    {
        self::$memoryRates[$date][$currency] = $rate;
    }

    private function readRedisRate(string $date, string $currency): ?float
    {
        if (!$this->redisEnabled()) {
            return null;
        }

        try {
            return $this->normalizeRate(Redis::hget($this->redisKey($date), $currency));
        } catch (\Throwable $e) {
            Log::warning('Read currency rate from Redis failed', [
                'date' => $date,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function readDatabaseRate(string $date, string $currency): ?float
    {
        try {
            $row = DB::table('currency_rates_daily')
                ->where('rate_date', $date)
                ->where('currency_code', $currency)
                ->first(['rate_to_usd']);
        } catch (\Throwable $e) {
            Log::warning('Read currency rate from DB failed', [
                'date' => $date,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $row === null ? null : $this->normalizeRate($row->rate_to_usd ?? null);
    }

    private function readFallbackDatabaseRate(string $date, string $currency): ?array
    {
        $fallbackDays = max(0, (int) config('currency_rate.fallback_days', 7));
        if ($fallbackDays <= 0) {
            return null;
        }

        $startDate = Carbon::parse($date, 'Asia/Shanghai')->subDays($fallbackDays)->toDateString();

        try {
            $row = DB::table('currency_rates_daily')
                ->where('currency_code', $currency)
                ->where('rate_date', '<', $date)
                ->where('rate_date', '>=', $startDate)
                ->orderByDesc('rate_date')
                ->first(['rate_date', 'rate_to_usd']);
        } catch (\Throwable $e) {
            Log::warning('Read fallback currency rate from DB failed', [
                'date' => $date,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $rate = $row === null ? null : $this->normalizeRate($row->rate_to_usd ?? null);
        if ($rate === null) {
            return null;
        }

        return [
            'date' => (string) $row->rate_date,
            'rate' => $rate,
        ];
    }

    private function overrideRate(string $currency): ?float
    {
        $overrides = config('currency_rate.override_to_usd', []);
        if (!is_array($overrides)) {
            return null;
        }

        foreach ($overrides as $code => $rate) {
            if ($this->normalizeCurrency($code) !== $currency) {
                continue;
            }

            return $this->normalizeRate($rate);
        }

        return null;
    }

    private function normalizeRates(array $rates): array
    {
        $normalized = [];
        foreach ($rates as $currency => $rate) {
            $currency = $this->normalizeCurrency($currency);
            $rate = $currency === self::BASE_CURRENCY ? 1.0 : $this->normalizeRate($rate);
            if ($currency === '' || $rate === null) {
                continue;
            }

            $normalized[$currency] = $rate;
        }

        return $normalized;
    }

    private function normalizeCurrencies(array $currencies): array
    {
        $normalized = [];
        foreach ($currencies as $currency) {
            $currency = $this->normalizeCurrency($currency);
            if ($currency !== '') {
                $normalized[$currency] = true;
            }
        }

        $normalized[self::BASE_CURRENCY] = true;

        return array_keys($normalized);
    }

    private function normalizeCurrency($currency): string
    {
        return substr(strtoupper(trim((string) $currency)), 0, 8);
    }

    private function normalizeDate(?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return Carbon::now('Asia/Shanghai')->toDateString();
        }

        try {
            return Carbon::parse($date, 'Asia/Shanghai')->toDateString();
        } catch (\Throwable) {
            return Carbon::now('Asia/Shanghai')->toDateString();
        }
    }

    private function normalizeRate($rate): ?float
    {
        if (!is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }

        return (float) $rate;
    }

    private function redisKey(string $date): string
    {
        return self::REDIS_KEY_PREFIX . $date;
    }

    private function redisEnabled(): bool
    {
        return (bool) config('currency_rate.redis_enabled', true);
    }

    private function redisTtlSeconds(): int
    {
        return max(60, (int) config('currency_rate.redis_ttl_seconds', 864000));
    }

    private static function formatRate(float $rate): string
    {
        return number_format($rate, 10, '.', '');
    }
}
