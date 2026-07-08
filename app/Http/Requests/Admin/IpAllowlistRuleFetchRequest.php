<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IpAllowlistRuleFetchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => 'nullable|boolean',
            'country' => 'nullable|string|max:16',
            'projectCode' => 'nullable|string|max:100',
            'packageName' => 'nullable|string|max:191',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
