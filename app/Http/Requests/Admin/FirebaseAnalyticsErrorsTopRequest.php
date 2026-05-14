<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsErrorsTopRequest extends FirebaseAnalyticsCommonQueryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'error_type' => 'required|string|in:vpn_session,vpn_probe,server_api',
            'limit'      => 'nullable|integer|min:1|max:200',
        ]);
    }
}
