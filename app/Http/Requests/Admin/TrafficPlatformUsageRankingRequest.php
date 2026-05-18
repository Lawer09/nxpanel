<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformUsageRankingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platformCode' => 'nullable|string|max:50',
            'startDate' => 'nullable|date',
            'endDate' => 'nullable|date',
            'rankBy' => 'nullable|in:account,external_uid,geo',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }
}
