<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CurrencyRateService;
use App\Services\UserAdValueReportService;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class UserAdValueReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('realtime:user_report:latest', [], 3600);
        CurrencyRateService::clearMemoryCache();
        config()->set('currency_rate.redis_enabled', false);
        config()->set('currency_rate.override_to_usd', []);

        $this->insertCurrencyRate('2026-07-03', 'CNY', 0.14);
        $this->insertCurrencyRate('2026-07-03', 'HKD', 0.1282051282);
        $this->insertCurrencyRate('2026-07-28', 'CNY', 0.14);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Verify batchReport accepts ad-value reports and keeps them in both raw queues.
     */
    public function test_batch_report_buffers_ads_value_reports_to_raw_payloads(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-28 12:34:00', 'Asia/Shanghai'));
        $capturedPayloads = [];
        $this->fakeRedisPipeline($capturedPayloads, 2);
        $user = $this->createUser('ad-value-buffer@example.com');

        $timestampMs = $this->timestampMs('2026-07-28 10:15:00');
        $adsValueReports = [
            ['value_micros' => 1000000, 'currency' => 'USD'],
            ['value_micros' => 2000000, 'currency' => 'CNY'],
        ];

        $this->postJson('/api/v3/user/performance/batchReport', [
            'reports' => [],
            'ads_value_reports' => $adsValueReports,
            'metadata' => [
                'app_id' => 'com.example.app',
                'app_version' => '1.0.0',
                'platform' => 'ios',
                'country' => 'us',
                'timestamp' => $timestampMs,
            ],
        ], $this->authHeaders($user))->assertOk();

        $this->assertCount(2, $capturedPayloads);
        foreach ($capturedPayloads as $payload) {
            $this->assertSame($adsValueReports, $payload['ads_value_reports']);
        }

        $latest = collect(Cache::get('realtime:user_report:latest', []))
            ->firstWhere('user_id', $user->id);
        $this->assertSame($adsValueReports, $latest['ads_value_reports']);
    }

    /**
     * Verify ads_value_reports remains optional.
     */
    public function test_batch_report_allows_missing_ads_value_reports(): void
    {
        $capturedPayloads = [];
        $this->fakeRedisPipeline($capturedPayloads, 2);
        $user = $this->createUser('ad-value-optional@example.com');

        $this->postJson('/api/v3/user/performance/batchReport', [
            'reports' => [
                [
                    'node_id' => 1,
                    'delay' => 80,
                    'success_rate' => 100,
                    'status' => 'success',
                ],
            ],
            'metadata' => [
                'app_id' => 'com.example.app',
                'timestamp' => $this->timestampMs('2026-07-28 10:15:00'),
            ],
        ], $this->authHeaders($user))->assertOk();

        $this->assertSame([], $capturedPayloads[0]['ads_value_reports']);
    }

    /**
     * Verify Form Request rejects malformed ad-value report items.
     */
    public function test_batch_report_validates_ads_value_reports_shape(): void
    {
        $user = $this->createUser('ad-value-validation@example.com');
        $metadata = [
            'app_id' => 'com.example.app',
            'timestamp' => $this->timestampMs('2026-07-28 10:15:00'),
        ];

        $this->postJson('/api/v3/user/performance/batchReport', [
            'reports' => [],
            'ads_value_reports' => array_fill(0, 101, ['value_micros' => 1, 'currency' => 'USD']),
            'metadata' => $metadata,
        ], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('code', 422);

        $this->postJson('/api/v3/user/performance/batchReport', [
            'reports' => [],
            'ads_value_reports' => [['currency' => 'USD']],
            'metadata' => $metadata,
        ], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('code', 422);

        $this->postJson('/api/v3/user/performance/batchReport', [
            'reports' => [],
            'ads_value_reports' => [['value_micros' => 1]],
            'metadata' => $metadata,
        ], $this->authHeaders($user))
            ->assertOk()
            ->assertJsonPath('code', 422);
    }

    /**
     * Verify aggregation converts daily snapshot currencies and skips unknown currencies.
     */
    public function test_service_aggregates_hourly_ad_value_with_daily_currency_rates(): void
    {
        $service = app(UserAdValueReportService::class);
        $this->insertUserReportCount('2026-07-01', 8, 0, 1001, 'com.example.app', [
            'platform' => 'ios',
            'app_version' => '0.9.0',
            'client_country' => 'US',
        ]);

        $service->aggregatePayloads([
            $this->rawPayload(1001, '2026-07-03 10:05:00', [
                ['value_micros' => 1000000, 'currency' => 'USD'],
                ['value_micros' => 1000000, 'currency' => 'CNY'],
                ['value_micros' => 7800000, 'currency' => 'HKD'],
                ['value_micros' => 999999, 'currency' => 'JPY'],
            ]),
            $this->rawPayload(1001, '2026-07-03 10:20:00', [
                ['value_micros' => 2000000, 'currency' => 'USD'],
            ]),
        ]);

        $row = DB::table('v3_user_ad_value_hourly')
            ->where('date', '2026-07-03')
            ->where('hour', 10)
            ->where('user_id', 1001)
            ->where('app_id', 'com.example.app')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(4140000, (int) $row->value_micros_usd);
        $this->assertSame('4.140000', number_format((float) $row->value_usd, 6, '.', ''));
        $this->assertSame(4, (int) $row->ad_value_report_count);

        $firstReport = DB::table('v3_user_app_first_report')
            ->where('user_id', 1001)
            ->where('app_id', 'com.example.app')
            ->first();

        $this->assertSame('2026-07-01', (string) $firstReport->first_report_date);
        $this->assertSame(8, (int) $firstReport->first_report_hour);
    }

    /**
     * Verify each payload uses the rate snapshot for its own report date.
     */
    public function test_service_uses_report_date_currency_rate_snapshot(): void
    {
        $this->insertCurrencyRate('2026-07-27', 'CNY', 0.10);
        $service = app(UserAdValueReportService::class);

        $service->aggregatePayloads([
            $this->rawPayload(1002, '2026-07-27 22:05:00', [
                ['value_micros' => 1000000, 'currency' => 'CNY'],
            ]),
            $this->rawPayload(1002, '2026-07-28 10:05:00', [
                ['value_micros' => 1000000, 'currency' => 'CNY'],
            ]),
        ]);

        $previousDay = DB::table('v3_user_ad_value_hourly')
            ->where('date', '2026-07-27')
            ->where('user_id', 1002)
            ->first();
        $currentDay = DB::table('v3_user_ad_value_hourly')
            ->where('date', '2026-07-28')
            ->where('user_id', 1002)
            ->first();

        $this->assertSame(100000, (int) $previousDay->value_micros_usd);
        $this->assertSame(140000, (int) $currentDay->value_micros_usd);
    }

    /**
     * Verify later aggregation stays tied to the stored daily snapshot after override changes.
     */
    public function test_service_replay_uses_stored_snapshot_for_stable_result(): void
    {
        $firstPayload = $this->rawPayload(1003, '2026-07-28 11:05:00', [
            ['value_micros' => 1000000, 'currency' => 'CNY'],
        ]);
        $secondPayload = $this->rawPayload(1004, '2026-07-28 11:05:00', [
            ['value_micros' => 1000000, 'currency' => 'CNY'],
        ]);
        $service = app(UserAdValueReportService::class);

        $service->aggregatePayloads([$firstPayload]);
        $firstValue = (int) DB::table('v3_user_ad_value_hourly')
            ->where('date', '2026-07-28')
            ->where('user_id', 1003)
            ->value('value_micros_usd');

        CurrencyRateService::clearMemoryCache();
        config()->set('currency_rate.override_to_usd', ['CNY' => 0.20]);

        $service->aggregatePayloads([$secondPayload]);
        $secondValue = (int) DB::table('v3_user_ad_value_hourly')
            ->where('date', '2026-07-28')
            ->where('user_id', 1004)
            ->value('value_micros_usd');

        $this->assertSame(140000, $firstValue);
        $this->assertSame($firstValue, $secondValue);
    }

    /**
     * Verify day-N value composition is calculated from per-app first report dates.
     */
    public function test_service_queries_cohort_value_composition(): void
    {
        $service = app(UserAdValueReportService::class);

        $this->insertFirstReport(3001, 'com.example.app', '2026-07-28');
        $this->insertFirstReport(3002, 'com.example.app', '2026-07-27');
        $this->insertFirstReport(3003, 'com.example.app', '2026-07-26');
        $this->insertAdValue('2026-07-28', 10, 3001, 500000000);
        $this->insertAdValue('2026-07-28', 10, 3002, 200000000);
        $this->insertAdValue('2026-07-28', 10, 3003, 100000000);
        $this->insertAdValue('2026-07-28', 10, 3999, 50000000);

        $result = $service->queryCohortValueComposition('2026-07-28', null, [
            'appId' => 'com.example.app',
        ]);

        $buckets = collect($result['buckets'])->keyBy('cohortKey');

        $this->assertSame(850000000, $result['totalValueMicrosUsd']);
        $this->assertSame('850.000000', $result['totalValueUsd']);
        $this->assertSame(500000000, $buckets['day0']['valueMicrosUsd']);
        $this->assertSame(200000000, $buckets['day1']['valueMicrosUsd']);
        $this->assertSame(100000000, $buckets['day2']['valueMicrosUsd']);
        $this->assertSame(50000000, $buckets['unknown']['valueMicrosUsd']);
        $this->assertSame(0.588235, $buckets['day0']['ratio']);
    }

    private function fakeRedisPipeline(array &$capturedPayloads, int $times): void
    {
        Redis::shouldReceive('pipeline')
            ->times($times)
            ->andReturnUsing(function (callable $callback) use (&$capturedPayloads) {
                $pipe = new class($capturedPayloads) {
                    private $capturedPayloads;

                    public function __construct(array &$capturedPayloads)
                    {
                        $this->capturedPayloads = &$capturedPayloads;
                    }

                    public function rpush(string $key, string $payload): int
                    {
                        $this->capturedPayloads[] = json_decode($payload, true);

                        return 1;
                    }

                    public function expire(string $key, int $seconds): bool
                    {
                        return true;
                    }
                };

                return $callback($pipe);
            });
    }

    private function createUser(string $email): User
    {
        return User::withoutEvents(fn(): User => User::query()->forceCreate([
            'email' => $email,
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'plan_id' => 1,
            'group_id' => 1,
            'expired_at' => time() + 86400,
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 1024 * 1024,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]));
    }

    private function authHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken,
        ];
    }

    private function rawPayload(int $userId, string $time, array $adsValueReports): array
    {
        return [
            'user_id' => $userId,
            'report_at' => $this->timestampMs($time),
            'received_at' => $this->timestampMs($time),
            'metadata' => [
                'app_id' => 'com.example.app',
                'app_version' => '1.0.0',
                'platform' => 'ios',
                'country' => 'US',
                'timestamp' => $this->timestampMs($time),
            ],
            'reports' => [],
            'ads_value_reports' => $adsValueReports,
            'user_default' => [],
            'client_ip' => '203.0.113.10',
        ];
    }

    private function timestampMs(string $time): int
    {
        return Carbon::parse($time, 'Asia/Shanghai')->utc()->getTimestampMs();
    }

    private function insertUserReportCount(string $date, int $hour, int $minute, int $userId, string $appId, array $overrides = []): void
    {
        DB::table('v3_user_report_count')->insert(array_replace([
            'date' => $date,
            'hour' => $hour,
            'minute' => $minute,
            'user_id' => $userId,
            'report_count' => 1,
            'node_count' => 0,
            'client_country' => null,
            'client_isp' => null,
            'platform' => null,
            'app_id' => $appId,
            'app_version' => null,
            'created_at' => now(),
        ], $overrides));
    }

    private function insertFirstReport(int $userId, string $appId, string $date): void
    {
        DB::table('v3_user_app_first_report')->insert([
            'user_id' => $userId,
            'app_id' => $appId,
            'first_report_date' => $date,
            'first_report_hour' => 0,
            'first_report_minute' => 0,
            'first_report_at_ms' => Carbon::parse($date . ' 00:00:00', 'Asia/Shanghai')->utc()->getTimestampMs(),
            'platform' => 'ios',
            'app_version' => '1.0.0',
            'country' => 'US',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAdValue(string $date, int $hour, int $userId, int $valueMicrosUsd): void
    {
        DB::table('v3_user_ad_value_hourly')->insert([
            'date' => $date,
            'hour' => $hour,
            'user_id' => $userId,
            'app_id' => 'com.example.app',
            'app_version' => '1.0.0',
            'platform' => 'ios',
            'country' => 'US',
            'value_micros_usd' => $valueMicrosUsd,
            'value_usd' => number_format($valueMicrosUsd / 1000000, 6, '.', ''),
            'ad_value_report_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
}
