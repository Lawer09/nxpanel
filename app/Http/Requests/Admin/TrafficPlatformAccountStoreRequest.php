<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformAccountStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platformCode' => 'required|string|max:50',
            'accountName' => 'required|string|max:100',
            'externalAccountId' => 'nullable|string|max:100',
            'credential' => 'required|array',
            'timezone' => 'nullable|string|max:64',
            'enabled' => 'nullable|integer|in:0,1',
            'balance' => 'nullable|integer|min:0',
        ];
    }
}
