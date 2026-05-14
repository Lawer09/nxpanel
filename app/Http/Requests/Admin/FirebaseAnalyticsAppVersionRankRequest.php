<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsAppVersionRankRequest extends FirebaseAnalyticsCommonQueryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'limit' => 'nullable|integer|min:1|max:200',
        ]);
    }
}
