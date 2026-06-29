<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsProbeResultsRequest extends FirebaseAnalyticsCommonQueryRequest
{
    /**
     * Validate Firebase VPN probe result detail query filters.
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:200',
            'event_id' => 'nullable|string|max:64',
            'probe_id' => 'nullable|string|max:64',
            'node_id' => 'nullable|string|max:128',
            'node_name' => 'nullable|string|max:128',
            'node_country' => 'nullable|string|max:16',
            'node_region' => 'nullable|string|max:64',
            'protocol' => 'nullable|string|max:64',
            'success' => 'nullable|boolean',
            'error_code' => 'nullable|string|max:64',
            'sort_by' => 'nullable|string|in:received_at,result_index,latency_ms,tcp_connect_ms,tls_hk_ms,proxy_hk_ms,timeout_ms,id',
            'order' => 'nullable|string|in:asc,desc',
        ]);
    }
}
