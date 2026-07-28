<?php

namespace App\Console\Commands;

use App\Services\CurrencyRateService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncCurrencyRates extends Command
{
    protected $signature = 'currency-rates:sync
        {--date= : 汇率日期 YYYY-MM-DD，默认今天}
        {--currencies= : 逗号分隔币种列表，默认使用配置 currency_rate.default_currencies}
        {--force : 已存在完整日快照时仍重新同步}';

    protected $description = '同步每日币种到 USD 汇率快照，并写入 Redis 缓存';

    /**
     * Synchronize configured currency rates into daily DB snapshots.
     */
    public function handle(CurrencyRateService $currencyRateService): int
    {
        $date = $this->resolveDate();
        if ($date === null) {
            return self::FAILURE;
        }

        $currencies = $this->resolveCurrencies($currencyRateService);
        if (empty($currencies)) {
            $this->error('No currencies configured for sync.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        if (!$force) {
            $storedRates = $currencyRateService->storedRatesForDate($date, $currencies);
            if ($this->hasAllRates($storedRates, $currencies)) {
                $currencyRateService->publishRatesToRedis($date, $storedRates);
                $this->info(sprintf('Currency rates snapshot already exists: date=%s currencies=%d', $date, count($currencies)));

                return self::SUCCESS;
            }
        }

        $overrideRates = $currencyRateService->overrideRates($currencies);
        $fetchCurrencies = array_values(array_diff($currencies, [CurrencyRateService::BASE_CURRENCY], array_keys($overrideRates)));
        $providerRates = [];
        $rawPayload = [
            'date' => $date,
            'currencies' => $currencies,
            'override_currencies' => array_keys($overrideRates),
        ];

        if (!empty($fetchCurrencies)) {
            try {
                [$providerRates, $providerPayload] = $this->fetchProviderRates($date, $fetchCurrencies);
                $rawPayload['provider'] = $providerPayload;
            } catch (\Throwable $e) {
                Log::error('currency-rates:sync provider request failed', [
                    'date' => $date,
                    'currencies' => $fetchCurrencies,
                    'error' => $e->getMessage(),
                ]);
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $rates = array_merge(
            [CurrencyRateService::BASE_CURRENCY => 1.0],
            $providerRates,
            $overrideRates
        );

        $missingCurrencies = array_values(array_filter($currencies, static fn(string $currency): bool => !array_key_exists($currency, $rates)));
        if (!empty($missingCurrencies)) {
            Log::error('currency-rates:sync missing provider rates', [
                'date' => $date,
                'missing_currencies' => $missingCurrencies,
            ]);
            $this->error('Missing currency rates: ' . implode(',', $missingCurrencies));

            return self::FAILURE;
        }

        $source = empty($fetchCurrencies)
            ? (empty($overrideRates) ? 'fixed' : 'override')
            : $this->providerSource();
        if (!empty($overrideRates)) {
            $source = substr($source . '+override', 0, 64);
        }

        $storedRates = $currencyRateService->storeRates($date, $rates, $source, $rawPayload);
        $this->info(sprintf('Currency rates synced: date=%s currencies=%d source=%s', $date, count($storedRates), $source));

        return self::SUCCESS;
    }

    private function resolveDate(): ?string
    {
        $date = $this->option('date');
        if ($date === null || trim((string) $date) === '') {
            return Carbon::now('Asia/Shanghai')->toDateString();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', (string) $date, 'Asia/Shanghai')->toDateString();
        } catch (\Throwable) {
            $this->error('Invalid --date. Expected YYYY-MM-DD.');

            return null;
        }
    }

    private function resolveCurrencies(CurrencyRateService $currencyRateService): array
    {
        $option = $this->option('currencies');
        if ($option === null || trim((string) $option) === '') {
            return $currencyRateService->defaultCurrencies();
        }

        return $this->normalizeCurrencies(explode(',', (string) $option));
    }

    /**
     * Fetch USD-base rates and convert them into currency-to-USD rates.
     */
    private function fetchProviderRates(string $date, array $currencies): array
    {
        $baseUrl = rtrim((string) config('currency_rate.provider_base_url', 'https://open.er-api.com/v6'), '/');
        if ($this->isOpenExchangeRateProvider($baseUrl)) {
            return $this->fetchOpenExchangeRateProviderRates($baseUrl, $date, $currencies);
        }

        $path = $date === Carbon::now('Asia/Shanghai')->toDateString() ? 'latest' : $date;
        $url = $baseUrl . '/' . $path;
        $query = [
            'base' => CurrencyRateService::BASE_CURRENCY,
            'symbols' => implode(',', $currencies),
        ];

        $accessKey = trim((string) config('currency_rate.provider_access_key', ''));
        if ($accessKey !== '') {
            $query['access_key'] = $accessKey;
        }

        $response = Http::timeout(max(1, (int) config('currency_rate.provider_timeout_seconds', 15)))
            ->get($url, $query);

        if (!$response->successful()) {
            throw new \RuntimeException(sprintf('Currency provider returned HTTP %d', $response->status()));
        }

        $payload = $response->json();
        if (!is_array($payload) || (($payload['success'] ?? true) === false) || !is_array($payload['rates'] ?? null)) {
            throw new \RuntimeException('Currency provider response is invalid.');
        }

        $providerRates = [];
        foreach ($currencies as $currency) {
            if ($currency === CurrencyRateService::BASE_CURRENCY) {
                $providerRates[$currency] = 1.0;
                continue;
            }

            $providerRate = $payload['rates'][$currency] ?? null;
            if (!is_numeric($providerRate) || (float) $providerRate <= 0) {
                continue;
            }

            $providerRates[$currency] = 1 / (float) $providerRate;
        }

        return [$providerRates, $payload];
    }

    /**
     * Fetch latest USD-base rates from open.er-api.com.
     */
    private function fetchOpenExchangeRateProviderRates(string $baseUrl, string $date, array $currencies): array
    {
        if ($date !== Carbon::now('Asia/Shanghai')->toDateString()) {
            throw new \RuntimeException('The default currency provider only supports latest rates. Configure a historical provider for --date.');
        }

        $response = Http::timeout(max(1, (int) config('currency_rate.provider_timeout_seconds', 15)))
            ->get($baseUrl . '/latest/' . CurrencyRateService::BASE_CURRENCY);

        if (!$response->successful()) {
            throw new \RuntimeException(sprintf('Currency provider returned HTTP %d', $response->status()));
        }

        $payload = $response->json();
        if (!is_array($payload) || (($payload['result'] ?? null) !== 'success') || !is_array($payload['rates'] ?? null)) {
            throw new \RuntimeException('Currency provider response is invalid.');
        }

        return [$this->convertUsdBaseRates($payload['rates'], $currencies), $payload];
    }

    private function convertUsdBaseRates(array $usdBaseRates, array $currencies): array
    {
        $providerRates = [];
        foreach ($currencies as $currency) {
            if ($currency === CurrencyRateService::BASE_CURRENCY) {
                $providerRates[$currency] = 1.0;
                continue;
            }

            $providerRate = $usdBaseRates[$currency] ?? null;
            if (!is_numeric($providerRate) || (float) $providerRate <= 0) {
                continue;
            }

            $providerRates[$currency] = 1 / (float) $providerRate;
        }

        return $providerRates;
    }

    private function normalizeCurrencies(array $currencies): array
    {
        $normalized = [];
        foreach ($currencies as $currency) {
            $currency = substr(strtoupper(trim((string) $currency)), 0, 8);
            if ($currency !== '') {
                $normalized[$currency] = true;
            }
        }

        $normalized[CurrencyRateService::BASE_CURRENCY] = true;

        return array_keys($normalized);
    }

    private function hasAllRates(array $rates, array $currencies): bool
    {
        foreach ($currencies as $currency) {
            if (!array_key_exists($currency, $rates)) {
                return false;
            }
        }

        return true;
    }

    private function providerSource(): string
    {
        $host = parse_url((string) config('currency_rate.provider_base_url', 'https://open.er-api.com/v6'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'provider';
    }

    private function isOpenExchangeRateProvider(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($host) && str_contains($host, 'open.er-api.com');
    }
}
