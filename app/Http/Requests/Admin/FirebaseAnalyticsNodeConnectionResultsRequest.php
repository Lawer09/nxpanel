<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsNodeConnectionResultsRequest extends FirebaseAnalyticsCommonQueryRequest
{
    /**
     * Validate Firebase single-node connection detail query filters.
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:200',
            'node_id' => 'nullable|string|max:128',
            'node_name' => 'nullable|string|max:128',
            'node_country' => 'nullable|string|max:16',
            'node_region' => 'nullable|string|max:64',
            'protocol' => 'nullable|string|max:64',
            'success' => 'nullable|boolean',
            'error_stage' => 'nullable|string|max:64',
            'error_code' => 'nullable|string|max:64',
            'sort_by' => 'nullable|string|in:received_at,event_time_ms,connect_ms,duration_ms,retry_count,id',
            'order' => 'nullable|string|in:asc,desc',
        ]);
    }
}
