<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdRevenueFetch extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sourcePlatform'    => 'nullable|string|max:32',
            'accountId'         => 'nullable|integer',
            'projectId'         => 'nullable|integer',
            'providerAppId'     => 'nullable|string|max:128',
            'providerAdUnitId'  => 'nullable|string|max:128',
            'countryCode'       => 'nullable|string|max:16',
            'devicePlatform'    => 'nullable|string|max:32',
            'adFormat'          => 'nullable|string|max:64',
            'reportType'        => 'nullable|string|max:32',
            'dateFrom'          => 'nullable|date',
            'dateTo'            => 'nullable|date',
            'page'              => 'nullable|integer|min:1',
            'pageSize'          => 'nullable|integer|min:1|max:200',
            'orderBy'           => 'nullable|string',
            'orderDir'          => 'nullable|in:asc,desc',
        ];
    }

    public function messages(): array
    {
        return [
            'pageSize.min' => '每页条数最小为1',
            'pageSize.max' => '每页条数最大为200',
        ];
    }
}
