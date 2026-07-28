<?php

$defaultCurrencies = [
    'USD',
    'CNY',
    'HKD',
    'EUR',
    'GBP',
    'JPY',
    'KRW',
    'INR',
    'BRL',
    'CAD',
    'AUD',
    'MXN',
    'IDR',
    'TRY',
    'RUB',
    'THB',
    'VND',
    'PHP',
    'MYR',
    'SGD',
    'TWD',
];

$configuredCurrencies = array_values(array_unique(array_filter(array_map(
    static fn($currency) => strtoupper(trim((string) $currency)),
    explode(',', (string) env('CURRENCY_RATE_SYNC_CURRENCIES', implode(',', $defaultCurrencies)))
))));

$overrideRates = json_decode((string) env('CURRENCY_RATE_OVERRIDE_TO_USD', '{}'), true);
if (!is_array($overrideRates)) {
    $overrideRates = [];
}

return [
    'provider_base_url' => env('CURRENCY_RATE_PROVIDER_BASE_URL', 'https://open.er-api.com/v6'),
    'provider_access_key' => env('CURRENCY_RATE_PROVIDER_ACCESS_KEY', ''),
    'provider_timeout_seconds' => (int) env('CURRENCY_RATE_PROVIDER_TIMEOUT_SECONDS', 15),
    'redis_enabled' => (bool) env('CURRENCY_RATE_REDIS_ENABLED', true),
    'redis_ttl_seconds' => (int) env('CURRENCY_RATE_REDIS_TTL_SECONDS', 864000),
    'fallback_days' => (int) env('CURRENCY_RATE_FALLBACK_DAYS', 7),
    'default_currencies' => $configuredCurrencies,
    'override_to_usd' => $overrideRates,
];
