<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdRevenueTopRank extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rankBy'         => 'required|in:app,ad_unit,country,account,platform',
            'metric'         => 'nullable|in:estimated_earnings,impressions,clicks,ecpm',
            'dateFrom'       => 'nullable|date',
            'dateTo'         => 'nullable|date',
            'sourcePlatform' => 'nullable|string|max:32',
            'accountId'      => 'nullable|integer',
            'projectId'      => 'nullable|integer',
            'limit'          => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'rankBy.required' => '排行维度不能为空',
            'rankBy.in'       => '排行维度格式有误，可选值：app, ad_unit, country, account, platform',
        ];
    }
}
