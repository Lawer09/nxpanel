<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectUserAppMapMappingRequest extends FormRequest
{
    /**
     * Allow admin users to fetch project code and package name mappings.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate project user app mapping query filters.
     */
    public function rules(): array
    {
        return [
            'projectCode' => 'nullable|string|max:100',
            'keyword' => 'nullable|string|max:255',
            'enabled' => 'nullable|integer|in:0,1',
            'includeDisabled' => 'nullable|boolean',
        ];
    }
}
