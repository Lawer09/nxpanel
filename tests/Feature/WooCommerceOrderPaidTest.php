<?php

namespace Tests\Feature;

use App\Models\AppClient;
use App\Models\ExternalOrderReceipt;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WooCommerceOrderPaidTest extends TestCase
{
    use RefreshDatabase;

    private AppClient $client;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = AppClient::create([
            'name' => 'WooCommerce',
            'app_id' => 'woo-app',
            'app_token' => 'woo-token',
            'app_secret' => 'woo-secret',
            'is_enabled' => true,
        ]);

        $this->plan = Plan::create([
            'group_id' => 1,
            'transfer_enable' => 10,
            'name' => '3 Month Plan',
            'show' => true,
            'renew' => true,
            'sell' => true,
            'prices' => ['quarterly' => 999],
            'sort' => 1,
        ]);

        Setting::createOrUpdate('woocommerce_product_mappings', [
            '68' => [
                'plan_id' => $this->plan->id,
                'period' => 'quarterly',
            ],
        ]);
    }

    public function test_processing_order_creates_and_opens_local_order(): void
    {
        $this->createDeviceUser('550E8400-E29B-41D4-A716-446655440000');

        $response = $this->postJson($this->endpoint(), $this->payload(), $this->headers());

        $response->assertOk()
            ->assertJsonPath('data.processed', true)
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.status', ExternalOrderReceipt::STATUS_PROCESSED);

        $this->assertDatabaseHas('external_order_receipts', [
            'provider' => ExternalOrderReceipt::PROVIDER_WOOCOMMERCE,
            'external_order_id' => '1234',
            'status' => ExternalOrderReceipt::STATUS_PROCESSED,
        ]);

        $this->assertSame(1, Order::where('status', Order::STATUS_COMPLETED)->count());
    }

    public function test_completed_duplicate_does_not_create_second_local_order(): void
    {
        $this->createDeviceUser('550E8400-E29B-41D4-A716-446655440000');

        $this->postJson($this->endpoint(), $this->payload(['trigger' => 'processing']), $this->headers())
            ->assertOk();

        $response = $this->postJson($this->endpoint(), $this->payload([
            'trigger' => 'completed',
            'order' => ['status' => 'completed'],
        ]), $this->headers());

        $response->assertOk()
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.processed', true);

        $this->assertSame(1, ExternalOrderReceipt::count());
        $this->assertSame(1, Order::count());
    }

    public function test_missing_device_id_is_validation_error(): void
    {
        $payload = $this->payload();
        unset($payload['tracking']['device_id']);

        $this->postJson($this->endpoint(), $payload, $this->headers())
            ->assertStatus(422);
    }

    public function test_unknown_user_is_recorded_as_failed_and_returns_ok(): void
    {
        $response = $this->postJson($this->endpoint(), $this->payload(), $this->headers());

        $response->assertOk()
            ->assertJsonPath('data.processed', false)
            ->assertJsonPath('data.status', ExternalOrderReceipt::STATUS_FAILED)
            ->assertJsonPath('data.reason', 'user_not_found');
    }

    public function test_unknown_product_mapping_is_recorded_as_failed_and_returns_ok(): void
    {
        $this->createDeviceUser('550E8400-E29B-41D4-A716-446655440000');

        $response = $this->postJson($this->endpoint(), $this->payload([
            'items' => [
                ['product_id' => 999, 'name' => 'Unknown', 'quantity' => 1, 'total' => '9.99'],
            ],
        ]), $this->headers());

        $response->assertOk()
            ->assertJsonPath('data.processed', false)
            ->assertJsonPath('data.status', ExternalOrderReceipt::STATUS_FAILED)
            ->assertJsonPath('data.reason', 'product_mapping_not_found');
    }

    private function createDeviceUser(string $deviceId): User
    {
        return User::create([
            'email' => $deviceId . '@apple.com',
            'password' => password_hash($deviceId, PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'expired_at' => 0,
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
        ]);
    }

    private function endpoint(): string
    {
        return '/api/v3/application/woocommerce/order/paid';
    }

    private function headers(): array
    {
        return [
            'X-App-Id' => $this->client->app_id,
            'X-App-Token' => $this->client->app_token,
        ];
    }

    private function payload(array $overrides = []): array
    {
        $payload = [
            'event' => 'woocommerce_order_paid',
            'time' => '2026-06-02 15:30:00',
            'site' => [
                'name' => 'RocketSpaceVPN',
                'url' => 'https://panel.rocketspacevpn.com',
            ],
            'order' => [
                'order_id' => 1234,
                'order_number' => '1234',
                'status' => 'processing',
                'currency' => 'USD',
                'total' => '9.99',
                'payment_method' => 'stripe',
                'payment_method_title' => 'Stripe',
                'transaction_id' => 'pi_xxx',
                'customer_id' => 88,
                'billing_email' => '6822590328@rocketspacevpn.com',
                'date_paid' => '2026-06-02 15:29:49',
            ],
            'tracking' => [
                'custom_tg_id' => '6822590328',
                'device_id' => '550E8400-E29B-41D4-A716-446655440000',
                '_vpn_sync_done' => 'yes',
            ],
            'items' => [
                ['product_id' => 68, 'name' => '3 Month Plan', 'quantity' => 1, 'total' => '9.99'],
            ],
            'trigger' => 'processing',
        ];

        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($payload[$key]) && is_array($payload[$key])) {
                $payload[$key] = array_replace_recursive($payload[$key], $value);
                continue;
            }
            $payload[$key] = $value;
        }

        return $payload;
    }
}
