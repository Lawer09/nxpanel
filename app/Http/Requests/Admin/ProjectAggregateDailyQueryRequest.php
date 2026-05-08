<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectAggregateDailyQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date',
            'groupBy' => 'nullable|array',
            'groupBy.*' => 'required|string|distinct|in:reportDate,projectCode,country',
            'filters' => 'nullable|array',
            'filters.projectCodes' => 'nullable|array',
            'filters.projectCodes.*' => 'string|max:100',
            'filters.countries' => 'nullable|array',
            'filters.countries.*' => 'string|max:50',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
            'orderBy' => 'nullable|string|in:reportDate,projectCode,country,newUsers,reportNewUsers,dauUsers,adRevenue,adRequests,adMatchedRequests,adImpressions,adClicks,adEcpm,adCtr,adMatchRate,adShowRate,adSpendCost,adSpendCpi,adSpendCpc,adSpendCpm,trafficUsageMb,trafficCost,profit,roi,id,updatedAt',
            'orderDirection' => 'nullable|string|in:asc,desc',
        ];
    }
}
