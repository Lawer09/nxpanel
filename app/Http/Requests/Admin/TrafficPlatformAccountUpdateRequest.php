<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'accountName' => 'nullable|string|max:100',
            'externalAccountId' => 'nullable|string|max:100',
            'credential' => 'nullable|array',
            'timezone' => 'nullable|string|max:64',
            'enabled' => 'nullable|integer|in:0,1',
            'balance' => 'nullable|integer|min:0',
        ];
    }
}
