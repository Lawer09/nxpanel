<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsNodesStatusRequest extends FirebaseAnalyticsCommonQueryRequest
{
    /**
     * Validate Firebase node status merged view query filters.
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
            'diagnosis_status' => 'nullable|string|in:connect_gap,probe_only,dual_risk,session_risk,probe_risk,session_only,healthy',
            'sample_scope' => 'nullable|string|in:all,probe_only,session_only,dual',
            'sort_by' => 'nullable|string|in:diagnosis_priority,rate_gap,probe_success_rate,session_success_rate,probe_test_count,session_count,p95_latency_ms,p95_connect_ms,last_probe_received_at,last_session_received_at',
            'order' => 'nullable|string|in:asc,desc',
        ]);
    }
}
