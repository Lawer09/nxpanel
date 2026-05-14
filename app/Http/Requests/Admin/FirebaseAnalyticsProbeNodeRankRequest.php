<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsProbeNodeRankRequest extends FirebaseAnalyticsCommonQueryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'sort_by' => 'nullable|string|in:success_rate,avg_latency_ms,p95_latency_ms,avg_tcp_connect_ms,avg_tls_hk_ms,avg_proxy_hk_ms',
            'order'   => 'nullable|string|in:asc,desc',
            'limit'   => 'nullable|integer|min:1|max:200',
        ]);
    }
}
