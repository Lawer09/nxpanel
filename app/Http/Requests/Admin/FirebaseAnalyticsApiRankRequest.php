<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsApiRankRequest extends FirebaseAnalyticsCommonQueryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'sort_by' => 'nullable|string|in:error_count,avg_duration_ms',
            'order'   => 'nullable|string|in:asc,desc',
            'limit'   => 'nullable|integer|min:1|max:200',
        ]);
    }
}
