<?php

namespace Tests\Feature;

use App\Services\CurrencyRateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class CurrencyRateTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_CURRENCIES = [
        'USD', 'CNY', 'HKD', 'EUR', 'GBP', 'JPY', 'KRW', 'INR', 'BRL', 'CAD', 'AUD',
        'MXN', 'IDR', 'TRY', 'RUB', 'THB', 'VND', 'PHP', 'MYR', 'SGD', 'TWD',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        CurrencyRateService::clearMemoryCache();
        config()->set('currency_rate.default_currencies', self::DEFAULT_CURRENCIES);
        config()->set('currency_rate.redis_enabled', false);
        config()->set('currency_rate.override_to_usd', []);
        config()->set('currency_rate.provider_base_url', 'http://currency.test');
        config()->set('currency_rate.provider_timeout_seconds', 3);
    }

    protected function tearDown(): void
    {
        CurrencyRateService::clearMemoryCache();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Verify USD is always treated as the fixed base currency.
     */
    public function test_usd_rate_is_fixed_to_one(): void
    {
        $this->assertSame(1.0, app(CurrencyRateService::class)->rateToUsd('usd', '2026-07-28'));
    }

    /**
     * Verify the default sync set contains 21 currencies including HKD.
     */
    public function test_default_sync_currencies_include_hkd(): void
    {
        $currencies = app(CurrencyRateService::class)->defaultCurrencies();

        $this->assertCount(21, $currencies);
        $this->assertContains('USD', $currencies);
        $this->assertContains('HKD', $currencies);
    }

    /**
     * Verify an in-process hit does not need Redis or DB on the second read.
     */
    public function test_memory_cache_hit_avoids_second_db_read(): void
    {
        $this->insertCurrencyRate('2026-07-28', 'HKD', 0.1282051282);
        $service = app(CurrencyRateService::class);

        $this->assertEqualsWithDelta(0.1282051282, $service->rateToUsd('HKD', '2026-07-28'), 0.0000000001);

        DB::table('currency_rates_daily')
            ->where('rate_date', '2026-07-28')
            ->where('currency_code', 'HKD')
            ->delete();

        $this->assertEqualsWithDelta(0.1282051282, $service->rateToUsd('HKD', '2026-07-28'), 0.0000000001);
    }

    /**
     * Verify Redis miss falls back to DB and warms Redis.
     */
    public function test_redis_miss_reads_db_snapshot_and_backfills_cache(): void
    {
        config()->set('currency_rate.redis_enabled', true);
        $this->insertCurrencyRate('2026-07-28', 'HKD', 0.1282051282);
        $redisWrite = [];

        Redis::shouldReceive('hget')
            ->once()
            ->with(CurrencyRateService::REDIS_KEY_PREFIX . '2026-07-28', 'HKD')
            ->andReturn(null);
        Redis::shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(function (callable $callback) use (&$redisWrite) {
                $pipe = new class($redisWrite) {
                    private $redisWrite;

                    public function __construct(array &$redisWrite)
                    {
                        $this->redisWrite = &$redisWrite;
                    }

                    public function hMSet(string $key, array $rates): bool
                    {
                        $this->redisWrite['key'] = $key;
                        $this->redisWrite['rates'] = $rates;

                        return true;
                    }

                    public function expire(string $key, int $seconds): bool
                    {
                        $this->redisWrite['ttl'] = $seconds;

                        return true;
                    }
                };

                return $callback($pipe);
            });

        $rate = app(CurrencyRateService::class)->rateToUsd('hkd', '2026-07-28');

        $this->assertEqualsWithDelta(0.1282051282, $rate, 0.0000000001);
        $this->assertSame(CurrencyRateService::REDIS_KEY_PREFIX . '2026-07-28', $redisWrite['key']);
        $this->assertSame('0.1282051282', $redisWrite['rates']['HKD']);
    }

    /**
     * Verify exact-date misses can use the nearest previous snapshot within the fallback window.
     */
    public function test_missing_exact_date_uses_recent_fallback_snapshot(): void
    {
        config()->set('currency_rate.fallback_days', 7);
        $this->insertCurrencyRate('2026-07-24', 'HKD', 0.13);

        $this->assertEqualsWithDelta(0.13, app(CurrencyRateService::class)->rateToUsd('HKD', '2026-07-28'), 0.0000000001);
    }

    /**
     * Verify unknown currencies return null without failing callers.
     */
    public function test_unknown_currency_returns_null(): void
    {
        $this->assertNull(app(CurrencyRateService::class)->rateToUsd('ZZZ', '2026-07-28'));
    }

    /**
     * Verify the sync command writes 21 DB snapshots and Redis hash data.
     */
    public function test_sync_command_writes_default_snapshots_and_redis_cache(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 08:00:00', 'Asia/Shanghai'));
        config()->set('currency_rate.redis_enabled', true);
        $redisWrite = [];
        $providerRates = $this->providerUsdBaseRates(app(CurrencyRateService::class)->defaultCurrencies());

        Http::fake([
            'http://currency.test/latest*' => Http::response([
                'success' => true,
                'base' => 'USD',
                'rates' => $providerRates,
            ]),
        ]);

        Redis::shouldReceive('pipeline')
            ->once()
            ->andReturnUsing(function (callable $callback) use (&$redisWrite) {
                $pipe = new class($redisWrite) {
                    private $redisWrite;

                    public function __construct(array &$redisWrite)
                    {
                        $this->redisWrite = &$redisWrite;
                    }

                    public function hMSet(string $key, array $rates): bool
                    {
                        $this->redisWrite['key'] = $key;
                        $this->redisWrite['rates'] = $rates;

                        return true;
                    }

                    public function expire(string $key, int $seconds): bool
                    {
                        $this->redisWrite['ttl'] = $seconds;

                        return true;
                    }
                };

                return $callback($pipe);
            });

        $this->artisan('currency-rates:sync')->assertExitCode(0);

        $this->assertSame(21, DB::table('currency_rates_daily')->where('rate_date', '2026-07-28')->count());
        $this->assertEqualsWithDelta(
            1 / 7.8,
            (float) DB::table('currency_rates_daily')
                ->where('rate_date', '2026-07-28')
                ->where('currency_code', 'HKD')
                ->value('rate_to_usd'),
            0.0000000001
        );
        $this->assertSame(CurrencyRateService::REDIS_KEY_PREFIX . '2026-07-28', $redisWrite['key']);
        $this->assertArrayHasKey('HKD', $redisWrite['rates']);

        Http::assertSent(fn($request): bool => str_starts_with($request->url(), 'http://currency.test/latest'));
    }

    /**
     * Verify provider failure does not overwrite existing snapshots or Redis data.
     */
    public function test_sync_command_failure_keeps_existing_snapshot(): void
    {
        config()->set('currency_rate.redis_enabled', true);
        $this->insertCurrencyRate('2026-07-28', 'HKD', 0.12);

        Http::fake([
            'http://currency.test/*' => Http::response(['success' => false], 500),
        ]);
        Redis::shouldReceive('pipeline')->never();

        $this->artisan('currency-rates:sync', [
            '--date' => '2026-07-28',
            '--currencies' => 'HKD',
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertEqualsWithDelta(
            0.12,
            (float) DB::table('currency_rates_daily')
                ->where('rate_date', '2026-07-28')
                ->where('currency_code', 'HKD')
                ->value('rate_to_usd'),
            0.0000000001
        );
    }

    private function insertCurrencyRate(string $date, string $currency, float $rateToUsd): void
    {
        DB::table('currency_rates_daily')->updateOrInsert(
            [
                'rate_date' => $date,
                'currency_code' => strtoupper($currency),
            ],
            [
                'base_currency' => 'USD',
                'rate_to_usd' => number_format($rateToUsd, 10, '.', ''),
                'source' => 'test',
                'synced_at' => now(),
                'raw_payload' => json_encode(['test' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    private function providerUsdBaseRates(array $currencies): array
    {
        $rates = [
            'CNY' => 7.1,
            'HKD' => 7.8,
            'EUR' => 0.92,
            'GBP' => 0.78,
            'JPY' => 155.0,
            'KRW' => 1380.0,
            'INR' => 83.0,
            'BRL' => 5.4,
            'CAD' => 1.36,
            'AUD' => 1.52,
            'MXN' => 18.1,
            'IDR' => 16200.0,
            'TRY' => 32.0,
            'RUB' => 90.0,
            'THB' => 36.5,
            'VND' => 25400.0,
            'PHP' => 58.0,
            'MYR' => 4.7,
            'SGD' => 1.35,
            'TWD' => 32.4,
        ];

        return array_intersect_key($rates, array_flip(array_diff($currencies, ['USD'])));
    }
}
