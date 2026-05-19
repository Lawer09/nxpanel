<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AutomationExecutionIndexRequest extends FormRequest
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
            'targetId' => 'nullable|string|max:64',
            'status' => 'nullable|string|in:triggered,recovered,skipped,failed',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:100',
        ];
    }
}
