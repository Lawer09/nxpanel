<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserReportNodeSummaryQueryRequest extends FormRequest
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
            'groupBy.*' => 'required|string|in:date,hour,node_id,node_host,node_type,probe_stage',
            'filters' => 'nullable|array',
            'filters.nodeIds' => 'nullable|array',
            'filters.nodeIds.*' => 'integer',
            'filters.nodeHosts' => 'nullable|array',
            'filters.nodeHosts.*' => 'string|max:255',
            'filters.probeStages' => 'nullable|array',
            'filters.probeStages.*' => 'string|max:32',
            'filters.nodeTypes' => 'nullable|array',
            'filters.nodeTypes.*' => 'string|max:32',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
            'orderBy' => 'nullable|string|in:date,hour,node_id,node_host,node_type,probe_stage,avg_delay,traffic_usage,traffic_use_time,compute_count,success_count,fail_count,success_rate,id,created_at,updated_at',
            'orderDirection' => 'nullable|string|in:asc,desc',
        ];
    }
}
