<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformAllocationCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accountId' => 'required|integer|min:1',
            'targetUserId' => 'required|string|max:100',
            'targetUsername' => 'required|string|max:100',
            'amountGb' => 'required|numeric|gt:0',
        ];
    }
}
