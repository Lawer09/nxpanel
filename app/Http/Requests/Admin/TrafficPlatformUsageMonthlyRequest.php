<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformUsageMonthlyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platformCode' => 'nullable|string|max:50',
            'accountId' => 'nullable|integer',
            'externalUid' => 'nullable|string|max:100',
            'startMonth' => 'nullable|string|max:7',
            'endMonth' => 'nullable|string|max:7',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
