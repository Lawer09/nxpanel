<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdRevenueTrend extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sourcePlatform'   => 'nullable|string|max:32',
            'accountId'        => 'nullable|integer',
            'projectId'        => 'nullable|integer',
            'providerAppId'    => 'nullable|string|max:128',
            'countryCode'      => 'nullable|string|max:16',
            'devicePlatform'   => 'nullable|string|max:32',
            'adFormat'         => 'nullable|string|max:64',
            'dateFrom'         => 'nullable|date',
            'dateTo'           => 'nullable|date',
            'compareDateFrom'  => 'nullable|date',
            'compareDateTo'    => 'nullable|date',
        ];
    }
}
