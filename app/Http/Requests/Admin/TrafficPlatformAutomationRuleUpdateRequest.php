<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformAutomationRuleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'name' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'targetType' => 'nullable|string|in:traffic_platform_account',
            'targetScope' => 'nullable|array',
            'targetScope.accountIds' => 'nullable|array',
            'targetScope.accountIds.*' => 'integer|min:1',
            'targetScope.platformCodes' => 'nullable|array',
            'targetScope.platformCodes.*' => 'string|max:50',
            'targetScope.includeDisabled' => 'nullable|integer|in:0,1',
            'conditionLogic' => 'nullable|string|in:all,any',
            'conditions' => 'nullable|array|min:1',
            'conditions.*.metric' => 'required_with:conditions|string|max:64',
            'conditions.*.operator' => 'required_with:conditions|string|in:eq,neq,gt,gte,lt,lte,in,not_in,between',
            'conditions.*.value' => 'required_with:conditions',
            'actions' => 'nullable|array|min:1',
            'actions.*.type' => 'required_with:actions|string|in:telegram_admin,email,disable_account',
            'actions.*.template' => 'nullable|string|max:1000',
            'actions.*.recoverTemplate' => 'nullable|string|max:1000',
            'actions.*.subject' => 'nullable|string|max:255',
            'actions.*.recoverSubject' => 'nullable|string|max:255',
            'actions.*.toAdmin' => 'nullable|integer|in:0,1',
            'actions.*.recipients' => 'nullable|array',
            'actions.*.recipients.*' => 'email',
            'cooldownSeconds' => 'nullable|integer|min:0|max:604800',
            'recoveryEnabled' => 'nullable|integer|in:0,1',
            'enabled' => 'nullable|integer|in:0,1',
        ];
    }
}
