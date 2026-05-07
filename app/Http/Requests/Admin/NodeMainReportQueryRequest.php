<?php

namespace App\Http\Requests\Admin;

use App\Services\NodeMainReportService;
use Illuminate\Foundation\Http\FormRequest;

class NodeMainReportQueryRequest extends FormRequest
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
            'groupBy' => 'required|array|min:1',
            'groupBy.*' => 'required|string|in:' . implode(',', NodeMainReportService::ALLOWED_GROUP_BY),
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
            'filters.nodeProtocols' => 'nullable|array',
            'filters.nodeProtocols.*' => 'string|max:32',
            'filters.includeExternal' => 'nullable|boolean',
            'fillUnknown' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
