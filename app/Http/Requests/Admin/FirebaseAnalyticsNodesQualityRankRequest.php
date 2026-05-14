<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsNodesQualityRankRequest extends FirebaseAnalyticsCommonQueryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'source'  => 'nullable|string|in:session,probe,mixed',
            'sort_by' => 'nullable|string|in:success_rate,avg_connect_ms,p95_connect_ms,total_bytes,session_count',
            'order'   => 'nullable|string|in:asc,desc',
            'limit'   => 'nullable|integer|min:1|max:200',
        ]);
    }
}
