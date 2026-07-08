<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IpAllowlistRuleUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|exists:ip_allowlist_rules,id',
            'name' => 'nullable|string|max:191',
            'enabled' => 'nullable|boolean',
            'countries' => 'nullable|array',
            'countries.*' => 'required|string|max:16|distinct',
            'projectCodes' => 'nullable|array',
            'projectCodes.*' => 'required|string|max:100|distinct',
            'packageNames' => 'nullable|array',
            'packageNames.*' => 'required|string|max:191|distinct',
            'reason' => 'nullable|string|max:500',
        ];
    }
}
