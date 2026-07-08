<?php

namespace Tests\Feature;

use App\Http\Controllers\V3\Admin\TrafficPlatform\TrafficPlatformAllocationController;
use App\Http\Requests\Admin\TrafficPlatformAllocationCreateRequest;
use App\Models\TrafficPlatform;
use App\Models\TrafficPlatformAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrafficPlatformAllocationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_traffic_allocation_order(): void
    {
        config()->set('services.traffic_platform_service.base_url', 'http://traffic-service.test');
        config()->set('services.traffic_platform_service.api_key', 'test-api-key');
        config()->set('services.traffic_platform_service.timeout_seconds', 5);

        $platform = TrafficPlatform::create([
            'code' => 'kkoip',
            'name' => 'KKOIP',
            'base_url' => 'https://www.kkoip.com',
            'enabled' => 1,
        ]);

        $account = TrafficPlatformAccount::create([
            'platform_id' => $platform->id,
            'platform_code' => $platform->code,
            'account_name' => 'Main Traffic Account',
            'external_account_id' => 'external-1',
            'credential_json' => [],
            'timezone' => 'Asia/Shanghai',
            'enabled' => 1,
            'balance' => 512,
        ]);

        Http::fake([
            'http://traffic-service.test/api/traffic-platform/traffic-allocations/orders' => Http::response([
                'code' => 0,
                'data' => ['order_id' => 'order-2001'],
            ], 200),
        ]);

        $request = TrafficPlatformAllocationCreateRequest::create('/api/v3/admin/test/traffic-platform/traffic-allocations/create', 'POST', [
            'accountId' => $account->id,
            'targetUserId' => '2',
            'targetUsername' => 'kookeey',
            'amountGb' => 10,
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(\Illuminate\Routing\Redirector::class));

        $response = app(TrafficPlatformAllocationController::class)->store($request);
        $payload = $response->getData(true);

        $this->assertSame(0, $payload['code']);
        $this->assertSame($account->id, $payload['data']['accountId']);
        $this->assertSame('2', $payload['data']['targetUserId']);
        $this->assertSame('kookeey', $payload['data']['targetUsername']);
        $this->assertSame(10.0, (float) $payload['data']['amountGb']);
        $this->assertSame('order-2001', $payload['data']['response']['data']['order_id']);

        Http::assertSent(function ($request) use ($account) {
            return $request->url() === 'http://traffic-service.test/api/traffic-platform/traffic-allocations/orders'
                && $request->header('X-API-Key') === ['test-api-key']
                && $request['account_id'] === $account->id
                && $request['target_user_id'] === '2'
                && $request['target_username'] === 'kookeey'
                && (float) $request['amount_gb'] === 10.0;
        });
    }
}
