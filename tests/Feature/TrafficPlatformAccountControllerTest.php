<?php

namespace Tests\Feature;

use App\Http\Controllers\V3\Admin\TrafficPlatform\TrafficPlatformAccountController;
use App\Http\Requests\Admin\IdRequest;
use App\Models\TrafficPlatform;
use App\Models\TrafficPlatformAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrafficPlatformAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_test_account_uses_configured_service_url_and_api_key(): void
    {
        config()->set('services.traffic_platform_service.base_url', 'http://traffic-service.test/');
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
            'http://traffic-service.test/api/traffic-platform/accounts/' . $account->id . '/test' => Http::response([
                'code' => 0,
                'data' => ['connected' => true],
            ], 200),
        ]);

        $request = IdRequest::create('/api/v3/admin/test/traffic-platform/accounts/test', 'POST', [
            'id' => $account->id,
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(\Illuminate\Routing\Redirector::class));

        $response = app(TrafficPlatformAccountController::class)->test($request);
        $payload = $response->getData(true);

        $this->assertSame(0, $payload['code']);
        $this->assertTrue($payload['data']['connected']);

        Http::assertSent(function ($request) use ($account) {
            return $request->url() === 'http://traffic-service.test/api/traffic-platform/accounts/' . $account->id . '/test'
                && $request->method() === 'POST'
                && $request->header('X-API-Key') === ['test-api-key']
                && $request->header('Content-Type') === ['application/json'];
        });
    }
}
