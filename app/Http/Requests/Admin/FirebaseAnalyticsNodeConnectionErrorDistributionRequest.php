<?php

namespace App\Http\Requests\Admin;

class FirebaseAnalyticsNodeConnectionErrorDistributionRequest extends FirebaseAnalyticsCommonQueryRequest
{
    /**
     * Validate Firebase single-node connection error distribution filters.
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'node_id' => 'nullable|string|max:128',
            'node_name' => 'nullable|string|max:128',
            'node_country' => 'nullable|string|max:16',
            'node_region' => 'nullable|string|max:64',
            'protocol' => 'nullable|string|max:64',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);
    }
}
