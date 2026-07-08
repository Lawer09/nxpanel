<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\AutomationRuleStoreRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class AutomationRuleRequestValidationTest extends TestCase
{
    public function test_traffic_allocation_action_requires_source_account_and_amount_fields(): void
    {
        $request = new AutomationRuleStoreRequest();
        $request->merge([
            'module' => 'traffic_platform',
            'name' => 'Low balance allocation',
            'conditions' => [
                ['metric' => 'balance_mb', 'operator' => 'lte', 'value' => 1024],
            ],
            'actions' => [
                ['type' => 'traffic_allocation'],
            ],
        ]);

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('actions.0.sourceAccountId', $validator->errors()->toArray());
        $this->assertArrayHasKey('actions.0.amountGb', $validator->errors()->toArray());
    }

    public function test_traffic_allocation_action_does_not_require_target_fields(): void
    {
        $request = new AutomationRuleStoreRequest();
        $request->merge([
            'module' => 'traffic_platform',
            'name' => 'Low balance allocation',
            'conditions' => [
                ['metric' => 'balance_mb', 'operator' => 'lte', 'value' => 1024],
            ],
            'actions' => [
                [
                    'type' => 'traffic_allocation',
                    'sourceAccountId' => 1,
                    'amountGb' => 10,
                ],
            ],
        ]);

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->fails());
    }

    public function test_webhook_action_keeps_existing_validation_behavior(): void
    {
        $request = new AutomationRuleStoreRequest();
        $request->merge([
            'module' => 'traffic_platform',
            'name' => 'Low balance webhook',
            'conditions' => [
                ['metric' => 'balance_mb', 'operator' => 'lte', 'value' => 1024],
            ],
            'actions' => [
                ['type' => 'webhook', 'webhookUrl' => 'https://example.com/webhook'],
            ],
        ]);

        $validator = Validator::make($request->all(), $request->rules());
        $request->withValidator($validator);

        $this->assertFalse($validator->fails());
    }
}
