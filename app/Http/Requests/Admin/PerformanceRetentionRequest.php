<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceRetentionRequest extends FormRequest
{
    /**
     * Allow admins to query user retention cohorts.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate retention query parameters.
     */
    public function rules(): array
    {
        return [
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date',
            'appId' => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:100',
        ];
    }
}
