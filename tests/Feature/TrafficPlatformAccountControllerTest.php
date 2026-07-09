<?php

namespace Tests\Feature;

use App\Http\Controllers\V3\Admin\TrafficPlatform\TrafficPlatformAccountController;
use App\Http\Requests\Admin\IdRequest;
use App\Http\Requests\Admin\TrafficPlatformAccountIndexRequest;
use App\Http\Requests\Admin\TrafficPlatformAccountStoreRequest;
use App\Http\Requests\Admin\TrafficPlatformAccountUpdateTagsRequest;
use App\Http\Requests\Admin\TrafficPlatformAccountUpdateRequest;
use App\Models\TrafficPlatform;
use App\Models\TrafficPlatformAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrafficPlatformAccountControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_tags_can_be_created_updated_and_filtered(): void
    {
        $platform = $this->createPlatform();

        $storeRequest = TrafficPlatformAccountStoreRequest::create('/api/v3/admin/test/traffic-platform/accounts/create', 'POST', [
            'platformCode' => $platform->code,
            'accountName' => 'Tagged Account',
            'externalAccountId' => 'external-tagged',
            'credential' => [],
            'tags' => [' main ', 'low-balance', '', 'main'],
        ]);
        $this->prepareRequest($storeRequest);

        $storeResponse = app(TrafficPlatformAccountController::class)->store($storeRequest);
        $storePayload = $storeResponse->getData(true);

        $this->assertSame(0, $storePayload['code']);
        $this->assertSame(['main', 'low-balance'], $storePayload['data']['tags']);

        $accountId = $storePayload['data']['id'];

        $this->createAccount($platform, 'Only Main', 'external-main', ['main']);
        $this->createAccount($platform, 'Only Low Balance', 'external-low', ['low-balance']);

        $indexRequest = TrafficPlatformAccountIndexRequest::create('/api/v3/admin/test/traffic-platform/accounts', 'GET', [
            'tags' => ['main', 'low-balance'],
        ]);
        $this->prepareRequest($indexRequest);

        $indexResponse = app(TrafficPlatformAccountController::class)->index($indexRequest);
        $indexPayload = $indexResponse->getData(true);

        $this->assertSame(0, $indexPayload['code']);
        $this->assertSame(1, $indexPayload['data']['total']);
        $this->assertSame($accountId, $indexPayload['data']['data'][0]['id']);

        $updateRequest = TrafficPlatformAccountUpdateRequest::create('/api/v3/admin/test/traffic-platform/accounts/update', 'POST', [
            'id' => $accountId,
            'tags' => ['vip', 'vip', ' allocated '],
        ]);
        $this->prepareRequest($updateRequest);

        $updateResponse = app(TrafficPlatformAccountController::class)->update($updateRequest);
        $updatePayload = $updateResponse->getData(true);

        $this->assertSame(0, $updatePayload['code']);
        $this->assertSame(['vip', 'allocated'], $updatePayload['data']['tags']);

        $updateTagsRequest = TrafficPlatformAccountUpdateTagsRequest::create('/api/v3/admin/test/traffic-platform/accounts/update-tags', 'POST', [
            'id' => $accountId,
            'tags' => [],
        ]);
        $this->prepareRequest($updateTagsRequest);

        $updateTagsResponse = app(TrafficPlatformAccountController::class)->updateTags($updateTagsRequest);
        $updateTagsPayload = $updateTagsResponse->getData(true);

        $this->assertSame(0, $updateTagsPayload['code']);
        $this->assertSame([], $updateTagsPayload['data']['tags']);
        $this->assertSame('Tagged Account', $updateTagsPayload['data']['accountName']);
    }

    public function test_account_detail_returns_empty_tags_for_null_value(): void
    {
        $platform = $this->createPlatform();
        $account = $this->createAccount($platform, 'No Tags Account', 'external-no-tags', null);

        $request = IdRequest::create('/api/v3/admin/test/traffic-platform/accounts/detail', 'GET', [
            'id' => $account->id,
        ]);
        $this->prepareRequest($request);

        $response = app(TrafficPlatformAccountController::class)->detail($request);
        $payload = $response->getData(true);

        $this->assertSame(0, $payload['code']);
        $this->assertSame([], $payload['data']['tags']);
    }

    public function test_test_account_uses_configured_service_url_and_api_key(): void
    {
        config()->set('services.traffic_platform_service.base_url', 'http://traffic-service.test/');
        config()->set('services.traffic_platform_service.api_key', 'test-api-key');
        config()->set('services.traffic_platform_service.timeout_seconds', 5);

        $platform = $this->createPlatform();
        $account = $this->createAccount($platform, 'Main Traffic Account', 'external-1', []);

        Http::fake([
            'http://traffic-service.test/api/traffic-platform/accounts/' . $account->id . '/test' => Http::response([
                'code' => 0,
                'data' => ['connected' => true],
            ], 200),
        ]);

        $request = IdRequest::create('/api/v3/admin/test/traffic-platform/accounts/test', 'POST', [
            'id' => $account->id,
        ]);
        $this->prepareRequest($request);

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

    private function createPlatform(): TrafficPlatform
    {
        return TrafficPlatform::create([
            'code' => 'kkoip',
            'name' => 'KKOIP',
            'base_url' => 'https://www.kkoip.com',
            'enabled' => 1,
        ]);
    }

    private function createAccount(TrafficPlatform $platform, string $name, string $externalId, ?array $tags): TrafficPlatformAccount
    {
        return TrafficPlatformAccount::create([
            'platform_id' => $platform->id,
            'platform_code' => $platform->code,
            'account_name' => $name,
            'external_account_id' => $externalId,
            'credential_json' => [],
            'timezone' => 'Asia/Shanghai',
            'enabled' => 1,
            'balance' => 512,
            'tags' => $tags,
        ]);
    }

    private function prepareRequest($request): void
    {
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make(\Illuminate\Routing\Redirector::class));
    }
}
