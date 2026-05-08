<?php

namespace App\Http\Requests\Admin;

use App\Services\NodeSubReportService;
use Illuminate\Foundation\Http\FormRequest;

class NodeSubReportQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subTable' => 'required|string|in:' . implode(',', NodeSubReportService::ALLOWED_SUB_TABLES),
            'date' => 'required|date',
            'hour' => 'nullable|integer|min:0|max:23',
            'minute' => 'nullable|integer|min:0|max:59',
            'groupBy' => 'nullable|array',
            'groupBy.*' => 'required|string|in:' . implode(',', NodeSubReportService::ALLOWED_GROUP_BY),
            'filters' => 'nullable|array',
            'filters.nodeIds' => 'nullable|array',
            'filters.nodeIds.*' => 'integer',
            'filters.appIds' => 'nullable|array',
            'filters.appIds.*' => 'string|max:255',
            'filters.appVersions' => 'nullable|array',
            'filters.appVersions.*' => 'string|max:50',
            'filters.platforms' => 'nullable|array',
            'filters.platforms.*' => 'string|max:100',
            'filters.clientCountries' => 'nullable|array',
            'filters.clientCountries.*' => 'string|max:2',
            'filters.clientIsps' => 'nullable|array',
            'filters.clientIsps.*' => 'string|max:255',
            'filters.statuses' => 'nullable|array',
            'filters.statuses.*' => 'string|max:32',
            'filters.probeStages' => 'nullable|array',
            'filters.probeStages.*' => 'string|max:64',
            'filters.errorCodes' => 'nullable|array',
            'filters.errorCodes.*' => 'string|max:64',
            'filters.includeExternal' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
            'orderBy' => 'nullable|string|max:64',
            'orderDirection' => 'nullable|string|in:asc,desc',
        ];
    }
}
