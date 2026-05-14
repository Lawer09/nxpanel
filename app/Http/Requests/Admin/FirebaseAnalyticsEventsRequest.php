<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsEventsRequest extends FirebaseAnalyticsCommonQueryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'page'        => 'nullable|integer|min:1',
            'page_size'   => 'nullable|integer|min:1|max:200',
            'event_id'    => 'nullable|string|max:64',
            'device_id'   => 'nullable|string|max:128',
            'user_id'     => 'nullable|string|max:128',
            'node_id'     => 'nullable|string|max:128',
            'api_path'    => 'nullable|string|max:255',
            'trace_id'    => 'nullable|string|max:128',
            'error_code'  => 'nullable|string|max:64',
            'success'     => 'nullable|boolean',
        ]);
    }
}
