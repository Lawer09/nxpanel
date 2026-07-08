<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\AutomationRuleState;
use App\Models\TrafficPlatform;
use App\Models\TrafficPlatformAccount;
use App\Services\Automation\AutomationActionDispatcher;
use App\Services\Automation\AutomationExecutionLogService;
use App\Services\Automation\TrafficPlatformAutomationService;
use App\Services\TrafficPlatform\TrafficPlatformAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class TrafficPlatformAllocationAutomationTest extends TestCase
{
    use RefreshDatabase;

    private TrafficPlatformAccount $account;

    private TrafficPlatformAccount $sourceAccount;

    private array $executionRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.traffic_platform_service.base_url', 'http://traffic-service.test');
        config()->set('services.traffic_platform_service.api_key', 'test-api-key');
        config()->set('services.traffic_platform_service.timeout_seconds', 5);

        $platform = TrafficPlatform::create([
            'code' => 'kkoip',
            'name' => 'KKOIP',
            'base_url' => 'https://www.kkoip.com',
            'enabled' => 1,
        ]);

        $this->account = TrafficPlatformAccount::create([
            'platform_id' => $platform->id,
            'platform_code' => $platform->code,
            'account_name' => 'Detected Traffic Account',
            'external_account_id' => 'detected-user-1',
            'credential_json' => [],
            'timezone' => 'Asia/Shanghai',
            'enabled' => 1,
            'balance' => 512,
        ]);

        $this->sourceAccount = TrafficPlatformAccount::create([
            'platform_id' => $platform->id,
            'platform_code' => $platform->code,
            'account_name' => 'Main Traffic Account',
            'external_account_id' => 'source-account',
            'credential_json' => [],
            'timezone' => 'Asia/Shanghai',
            'enabled' => 1,
            'balance' => 102400,
        ]);
    }

    public function test_traffic_allocation_action_creates_order_when_rule_matches(): void
    {
        Http::fake([
            'http://traffic-service.test/api/traffic-platform/traffic-allocations/orders' => Http::response([
                'code' => 0,
                'data' => ['order_id' => 'order-1001'],
            ], 200),
        ]);

        $rule = $this->createAllocationRule();
        $summary = $this->automationService()->run(['ruleId' => $rule->id]);

        $this->assertSame(1, $summary['triggeredCount']);
        $this->assertSame(0, $summary['failedCount']);

        Http::assertSent(function ($request) {
            return $request->url() === 'http://traffic-service.test/api/traffic-platform/traffic-allocations/orders'
                && $request->method() === 'POST'
                && $request->header('X-API-Key') === ['test-api-key']
                && $request['account_id'] === $this->sourceAccount->id
                && $request['target_user_id'] === 'detected-user-1'
                && $request['target_username'] === 'Detected Traffic Account'
                && (float) $request['amount_gb'] === 10.0;
        });

        $this->assertSame('triggered', $this->executionRecords[0]['status']);
        $this->assertSame('traffic_allocation', $this->executionRecords[0]['action_results'][0]['type']);
        $this->assertSame(200, $this->executionRecords[0]['action_results'][0]['statusCode']);
    }

    public function test_dry_run_does_not_send_traffic_allocation_request(): void
    {
        Http::fake();

        $rule = $this->createAllocationRule();
        $summary = $this->automationService()->run([
            'ruleId' => $rule->id,
            'dryRun' => true,
        ]);

        $this->assertSame(0, $summary['triggeredCount']);
        $this->assertSame(1, $summary['skippedCount']);
        Http::assertNothingSent();
        $this->assertSame('dry_run', $this->executionRecords[0]['actions_snapshot']['reason']);
    }

    public function test_recovery_stage_skips_traffic_allocation_request(): void
    {
        Http::fake();

        $rule = $this->createAllocationRule([
            'conditions_json' => [
                ['metric' => 'balance_mb', 'operator' => 'lte', 'value' => 100],
            ],
            'recovery_enabled' => 1,
        ]);

        AutomationRuleState::create([
            'rule_id' => $rule->id,
            'target_type' => 'traffic_platform_account',
            'target_id' => (string) $this->account->id,
            'status' => AutomationRuleState::STATUS_ALERTING,
        ]);

        $summary = $this->automationService()->run(['ruleId' => $rule->id]);

        $this->assertSame(1, $summary['recoveredCount']);
        Http::assertNothingSent();
        $this->assertSame('traffic_allocation', $this->executionRecords[0]['action_results'][0]['type']);
        $this->assertTrue($this->executionRecords[0]['action_results'][0]['skipped']);
    }

    public function test_failed_traffic_allocation_is_logged_without_api_key(): void
    {
        Http::fake([
            'http://traffic-service.test/api/traffic-platform/traffic-allocations/orders' => Http::response([
                'message' => 'upstream failed',
            ], 500),
        ]);

        $rule = $this->createAllocationRule();
        $summary = $this->automationService()->run(['ruleId' => $rule->id]);

        $this->assertSame(1, $summary['failedCount']);
        $this->assertSame('failed', $this->executionRecords[0]['status']);
        $this->assertStringContainsString('status 500', $this->executionRecords[0]['error_message']);
        $this->assertStringNotContainsString('test-api-key', $this->executionRecords[0]['error_message']);
    }

    private function createAllocationRule(array $overrides = []): AutomationRule
    {
        return AutomationRule::create(array_merge([
            'module' => 'traffic_platform',
            'name' => 'Low balance traffic allocation',
            'target_type' => 'traffic_platform_account',
            'target_scope_json' => [
                'accountIds' => [$this->account->id],
                'includeDisabled' => 0,
            ],
            'condition_logic' => AutomationRule::LOGIC_ALL,
            'conditions_json' => [
                ['metric' => 'balance_mb', 'operator' => 'lte', 'value' => 1024],
            ],
            'actions_json' => [
                [
                    'type' => 'traffic_allocation',
                    'sourceAccountId' => $this->sourceAccount->id,
                    'amountGb' => 10,
                ],
            ],
            'cooldown_seconds' => 3600,
            'recovery_enabled' => 0,
            'enabled' => 1,
        ], $overrides));
    }

    private function automationService(): TrafficPlatformAutomationService
    {
        $executionLogService = Mockery::mock(AutomationExecutionLogService::class);
        $executionLogService->shouldReceive('appendExecution')
            ->andReturnUsing(function (string $module, array $record) {
                $this->executionRecords[] = $record;
            });

        return new TrafficPlatformAutomationService(
            $executionLogService,
            app(AutomationActionDispatcher::class),
            app(TrafficPlatformAllocationService::class)
        );
    }
}
