<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdRevenueAggregate extends FormRequest
{
    // 允许的聚合维度白名单
    private const ALLOWED_DIMENSIONS = [
        'reportDate', 'sourcePlatform', 'accountId',
        'providerAppId', 'providerAdUnitId',
        'countryCode', 'devicePlatform', 'adFormat',
        'reportType', 'adSourceCode',
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
