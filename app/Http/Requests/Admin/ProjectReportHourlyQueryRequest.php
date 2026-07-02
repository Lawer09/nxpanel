<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectReportHourlyQueryRequest extends FormRequest
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
            'hourFrom' => 'nullable|integer|min:0|max:23',
            'hourTo' => 'nullable|integer|min:0|max:23',
            'groupBy' => 'nullable|array',
            'groupBy.*' => 'required|string|distinct|in:reportDate,hour,projectCode,country',
            'filters' => 'nullable|array',
            'filters.projectCodes' => 'nullable|array',
            'filters.projectCodes.*' => 'string|max:100',
            'filters.countries' => 'nullable|array',
            'filters.countries.*' => 'string|max:50',
            'filters.exclude' => 'nullable|array',
            'filters.exclude.projectCodes' => 'nullable|array',
            'filters.exclude.projectCodes.*' => 'string|max:100',
            'filters.exclude.countries' => 'nullable|array',
            'filters.exclude.countries.*' => 'string|max:50',
            'filters.adStatuses' => 'nullable|array',
            'filters.adStatuses.*' => 'string|max:50',
            'filters.appPlatforms' => 'nullable|array',
            'filters.appPlatforms.*' => 'string|max:50',
            'filters.departments' => 'nullable|array',
            'filters.departments.*' => 'string|max:100',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:400',
            'orderBy' => 'nullable|string|in:reportDate,hour,projectCode,country,newUsers,reportNewUsers,fbNewUsers,dauUsers,fbDauUsers,adRevenue,adRequests,adMatchedRequests,adImpressions,adClicks,adEcpm,adCtr,adMatchRate,adShowRate,adSpendCost,adSpendCpi,adSpendCpc,adSpendCpm,trafficUsageMb,trafficCost,totalCost,trafficCostRatio,profit,roi,id,updatedAt',
            'orderDirection' => 'nullable|string|in:asc,desc',
        ];
    }
}
