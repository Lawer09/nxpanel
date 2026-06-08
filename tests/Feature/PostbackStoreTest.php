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
            'package' => 'pupu.test.cc',
            'clickid' => 'click_001',
            'deviceid' => 'device_001',
        ]));

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.stored', true)
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.packageName', 'pupu.test.cc')
            ->assertJsonPath('data.clickid', 'click_001')
            ->assertJsonPath('data.deviceid', 'device_001');

        $this->assertDatabaseHas('postback_receipts', [
            'package_name' => 'pupu.test.cc',
            'clickid' => 'click_001',
            'deviceid' => 'device_001',
        ]);
    }

    public function test_missing_clickid_is_validation_error(): void
    {
        $this->getJson($this->endpoint([
            'package' => 'pupu.test.cc',
            'deviceid' => 'device_001',
        ]))->assertStatus(422);
    }

    public function test_missing_deviceid_is_validation_error(): void
    {
        $this->getJson($this->endpoint([
            'package' => 'pupu.test.cc',
            'clickid' => 'click_001',
        ]))->assertStatus(422);
    }

    public function test_duplicate_clickid_is_not_stored_twice(): void
    {
        $this->getJson($this->endpoint([
            'package' => 'pupu.test.cc',
            'clickid' => 'click_001',
            'deviceid' => 'device_001',
        ]))->assertOk();

        $response = $this->getJson($this->endpoint([
            'package' => 'pupu.test.cc',
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
            'package_name' => 'pupu.test.cc',
            'clickid' => 'click_001',
            'deviceid' => 'device_001',
        ]);
    }

    public function test_same_clickid_can_be_stored_for_different_packages(): void
    {
        $this->getJson($this->endpoint([
            'package' => 'pupu.test.cc',
            'clickid' => 'click_001',
            'deviceid' => 'device_001',
        ]))->assertOk();

        $response = $this->getJson($this->endpoint([
            'package' => 'com.jkcl.zwx.vpn',
            'clickid' => 'click_001',
            'deviceid' => 'device_999',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.stored', true)
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('data.packageName', 'com.jkcl.zwx.vpn');

        $this->assertSame(2, PostbackReceipt::count());
    }

    private function endpoint(array $query): string
    {
        $package = $query['package'];
        unset($query['package']);

        return '/api/v3/pb/' . $package . '?' . http_build_query($query);
    }
}
