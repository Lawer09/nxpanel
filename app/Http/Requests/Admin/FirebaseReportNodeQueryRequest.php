<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

class FirebaseReportNodeQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Default Firebase node summary queries to today's date when no date range is provided.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('dateFrom') || $this->filled('dateTo')) {
            return;
        }

        $today = Carbon::today()->toDateString();

        $this->merge([
            'dateFrom' => $today,
            'dateTo' => $today,
        ]);
    }

    public function rules(): array
    {
        return [
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date',
            'hourFrom' => 'nullable|integer|min:0|max:23',
            'hourTo' => 'nullable|integer|min:0|max:23',
            'groupBy' => 'nullable|array',
            'groupBy.*' => 'required|string|in:date,hour,app_id,app_version,country,node_id,node_host,node_name,node_country,node_region,protocol',
            'filters' => 'nullable|array',
            'filters.appIds' => 'nullable|array',
            'filters.appIds.*' => 'string|max:128',
            'filters.appVersions' => 'nullable|array',
            'filters.appVersions.*' => 'string|max:64',
            'filters.countries' => 'nullable|array',
            'filters.countries.*' => 'string|max:16',
            'filters.nodeIds' => 'nullable|array',
            'filters.nodeIds.*' => 'string|max:128',
            'filters.nodeHosts' => 'nullable|array',
            'filters.nodeHosts.*' => 'string|max:255',
            'filters.nodeCountries' => 'nullable|array',
            'filters.nodeCountries.*' => 'string|max:16',
            'filters.protocols' => 'nullable|array',
            'filters.protocols.*' => 'string|max:64',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
            'orderBy' => 'nullable|string|in:date,hour,app_id,app_version,country,node_id,node_host,node_name,node_country,node_region,protocol,total_count,success_count,fail_count,success_rate,avg_connect_ms,max_connect_ms,id,created_at,updated_at',
            'orderDirection' => 'nullable|string|in:asc,desc',
        ];
    }
}
