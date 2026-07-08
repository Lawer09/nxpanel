<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AutomationRuleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module' => 'required|string|max:64',
            'id' => 'required|integer|min:1',
            'name' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'targetType' => 'nullable|string|max:64',
            'targetScope' => 'nullable|array',
            'targetScope.targetIds' => 'nullable|array',
            'targetScope.targetIds.*' => 'string|max:64',
            'targetScope.accountIds' => 'nullable|array',
            'targetScope.accountIds.*' => 'integer|min:1',
            'targetScope.projectCodes' => 'nullable|array',
            'targetScope.projectCodes.*' => 'string|max:64',
            'targetScope.platformCodes' => 'nullable|array',
            'targetScope.platformCodes.*' => 'string|max:50',
            'targetScope.includeDisabled' => 'nullable|integer|in:0,1',
            'conditionLogic' => 'nullable|string|in:all,any',
            'conditions' => 'nullable|array|min:1',
            'conditions.*.metric' => 'required_with:conditions|string|max:64',
            'conditions.*.operator' => 'required_with:conditions|string|in:eq,neq,gt,gte,lt,lte,in,not_in,between',
            'conditions.*.value' => 'required_with:conditions',
            'actions' => 'nullable|array|min:1',
            'actions.*.type' => 'required_with:actions|string|max:64',
            'actions.*.template' => 'nullable|string|max:1000',
            'actions.*.recoverTemplate' => 'nullable|string|max:1000',
            'actions.*.subject' => 'nullable|string|max:255',
            'actions.*.recoverSubject' => 'nullable|string|max:255',
            'actions.*.toAdmin' => 'nullable|integer|in:0,1',
            'actions.*.recipients' => 'nullable|array',
            'actions.*.recipients.*' => 'email',
            'actions.*.webhookUrl' => 'nullable|url|max:2048',
            'actions.*.method' => 'nullable|string|in:POST,PUT,PATCH',
            'actions.*.headers' => 'nullable|array',
            'actions.*.headers.*' => 'nullable|string|max:1000',
            'actions.*.timeoutSeconds' => 'nullable|integer|min:1|max:60',
            'actions.*.signing' => 'nullable|array',
            'actions.*.signing.enabled' => 'nullable|integer|in:0,1',
            'actions.*.signing.secret' => 'nullable|string|max:500',
            'actions.*.signing.timestampHeader' => 'nullable|string|max:100',
            'actions.*.signing.signatureHeader' => 'nullable|string|max:100',
            'actions.*.sourceAccountId' => 'nullable|integer|min:1',
            'actions.*.targetUserId' => 'nullable|string|max:100',
            'actions.*.targetUsername' => 'nullable|string|max:100',
            'actions.*.amountGb' => 'nullable|numeric|gt:0',
            'cooldownSeconds' => 'nullable|integer|min:0|max:604800',
            'recoveryEnabled' => 'nullable|integer|in:0,1',
            'enabled' => 'nullable|integer|in:0,1',
        ];
    }

    /**
     * 校验 traffic_platform 专属流量划转动作的必要参数。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('actions', []) as $index => $action) {
                if ((string) ($action['type'] ?? '') !== 'traffic_allocation') {
                    continue;
                }

                if (!array_key_exists('sourceAccountId', $action) || $action['sourceAccountId'] === null || $action['sourceAccountId'] === '') {
                    $validator->errors()->add("actions.{$index}.sourceAccountId", 'sourceAccountId is required for traffic_allocation action.');
                }

                if (!array_key_exists('amountGb', $action) || $action['amountGb'] === null || $action['amountGb'] === '') {
                    $validator->errors()->add("actions.{$index}.amountGb", 'amountGb is required for traffic_allocation action.');
                }
            }
        });
    }
}
