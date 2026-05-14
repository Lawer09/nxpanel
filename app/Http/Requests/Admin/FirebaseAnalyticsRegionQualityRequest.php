<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsRegionQualityRequest extends FirebaseAnalyticsCommonQueryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'sort_by' => 'nullable|string|in:event_count,vpn_success_rate,api_error_count,avg_connect_ms',
            'order'   => 'nullable|string|in:asc,desc',
            'limit'   => 'nullable|integer|min:1|max:200',
        ]);
    }
}
