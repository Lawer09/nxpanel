<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdRevenueNowDiffRequest extends FormRequest
{
    /**
     * Allow authenticated admin route middleware to handle access control.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate filters, pagination, and sortable fields for now-vs-daily revenue diff.
     */
    public function rules(): array
    {
        return [
            'dateFrom'       => 'nullable|date',
            'dateTo'         => 'nullable|date|after_or_equal:dateFrom',
            'sourcePlatform' => 'nullable|string|max:32',
            'reportType'     => 'nullable|string|max:32',
            'accountId'      => 'nullable|integer',
            'providerAppId'  => 'nullable|string|max:128',
            'devicePlatform' => 'nullable|string|max:32',
            'projectCode'    => 'nullable|string|max:100',
            'page'           => 'nullable|integer|min:1',
            'pageSize'       => 'nullable|integer|min:1|max:200',
            'orderBy'        => 'nullable|string|in:reportDate,accountId,providerAppId,devicePlatform,projectCode,nowEstimatedEarnings,dailyEstimatedEarnings,estimatedEarningsDiff,nowUpdatedAt,dailyUpdatedAt',
            'orderDir'       => 'nullable|string|in:asc,desc',
        ];
    }

    /**
     * Return readable validation messages for admin API consumers.
     */
    public function messages(): array
    {
        return [
            'dateTo.after_or_equal' => 'dateTo must be after or equal to dateFrom',
            'pageSize.min' => 'pageSize must be at least 1',
            'pageSize.max' => 'pageSize must not exceed 200',
            'orderBy.in' => 'orderBy is not supported',
            'orderDir.in' => 'orderDir must be asc or desc',
        ];
    }
}
