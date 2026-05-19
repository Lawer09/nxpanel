<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AutomationRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'module' => 'required|string|max:64',
            'ruleId' => 'nullable|integer|min:1',
            'targetIds' => 'nullable|array',
            'targetIds.*' => 'string|max:64',
            'dryRun' => 'nullable|integer|in:0,1',
        ];
    }
}
