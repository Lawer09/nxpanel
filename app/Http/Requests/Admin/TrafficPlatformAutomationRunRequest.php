<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformAutomationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ruleId' => 'nullable|integer|min:1',
            'accountIds' => 'nullable|array',
            'accountIds.*' => 'integer|min:1',
            'dryRun' => 'nullable|integer|in:0,1',
        ];
    }
}
