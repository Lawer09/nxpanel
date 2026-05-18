<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformUsageTrendRequest extends FormRequest
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
            'startDate' => 'nullable|date',
            'endDate' => 'nullable|date',
            'dimension' => 'nullable|in:hour,day,month',
        ];
    }
}
