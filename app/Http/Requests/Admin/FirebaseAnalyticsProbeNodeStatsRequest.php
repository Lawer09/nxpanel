<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsProbeNodeStatsRequest extends FirebaseAnalyticsCommonQueryRequest
{
    /**
     * Validate Firebase VPN probe node statistics query filters.
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|min:1|max:200',
            'probe_id' => 'nullable|string|max:64',
            'node_id' => 'nullable|string|max:128',
            'node_name' => 'nullable|string|max:128',
            'node_country' => 'nullable|string|max:16',
            'node_region' => 'nullable|string|max:64',
            'protocol' => 'nullable|string|max:64',
            'sort_by' => 'nullable|string|in:node_id,test_count,success_count,fail_count,success_rate,avg_latency_ms,p95_latency_ms,avg_tcp_connect_ms,avg_tls_hk_ms,avg_proxy_hk_ms,last_received_at',
            'order' => 'nullable|string|in:asc,desc',
        ]);
    }
}
