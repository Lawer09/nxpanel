<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdRevenueAggregate extends FormRequest
{
    // 允许的聚合维度白名单
    private const ALLOWED_DIMENSIONS = [
        'report_date', 'source_platform', 'account_id',
        'provider_app_id', 'provider_ad_unit_id',
        'country_code', 'device_platform', 'ad_format',
        'report_type', 'ad_source_code',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'groupBy'           => 'required|array|min:1',
            'groupBy.*'         => 'string|in:' . implode(',', self::ALLOWED_DIMENSIONS),
            'sourcePlatform'    => 'nullable|string|max:32',
            'accountId'         => 'nullable|integer',
            'projectId'         => 'nullable|integer',
            'providerAppId'     => 'nullable|string|max:128',
            'countryCode'       => 'nullable|string|max:16',
            'devicePlatform'    => 'nullable|string|max:32',
            'adFormat'          => 'nullable|string|max:64',
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
            'groupBy.required' => '聚合维度不能为空',
            'groupBy.min'      => '至少选择一个聚合维度',
            'pageSize.min'     => '每页条数最小为1',
            'pageSize.max'     => '每页条数最大为200',
        ];
    }
}
