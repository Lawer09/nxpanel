<?php

namespace Tests\Feature;

use App\Models\PostbackReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostbackStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_postback_request_is_stored(): void
    {
        $response = $this->getJson($this->endpoint([
            'clickid' => 'click_001',
            'deviceid' => 'device_001',
        ]));

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.stored', true)
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.packageName', PostbackReceipt::PACKAGE_NAME)
            ->assertJsonPath('data.clickid', 'click_001')
            ->assertJsonPath('data.deviceid', 'device_001');

        $this->assertDatabaseHas('postback_receipts', [
            'package_name' => PostbackReceipt::PACKAGE_NAME,
            'clickid' => 'click_001',
            'deviceid' => 'device_001',
        ]);
    }

    public function test_missing_clickid_is_validation_error(): void
    {
        $this->getJson($this->endpoint([
            'deviceid' => 'device_001',
        ]))->assertStatus(422);
    }

    public function test_missing_deviceid_is_validation_error(): void
    {
        $this->getJson($this->endpoint([
            'clickid' => 'click_001',
        ]))->assertStatus(422);
    }

    public function test_duplicate_clickid_is_not_stored_twice(): void
    {
        $this->getJson($this->endpoint([
            'clickid' => 'click_001',
            'deviceid' => 'device_001',
        ]))->assertOk();

        $response = $this->getJson($this->endpoint([
            'clickid' => 'click_001',
            'deviceid' => 'device_002',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.stored', false)
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.clickid', 'click_001')
            ->assertJsonPath('data.deviceid', 'device_002');

        $this->assertSame(1, PostbackReceipt::count());
        $this->assertDatabaseHas('postback_receipts', [
            'clickid' => 'click_001',
            'deviceid' => 'device_001',
        ]);
    }

    private function endpoint(array $query): string
    {
        return '/pb/com.jkcl.zwx.vpn?' . http_build_query($query);
    }
}
