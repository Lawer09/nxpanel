<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceActiveUsersRequest extends FormRequest
{
    /**
     * Allow admins to query active user trends.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate active user trend query parameters.
     */
    public function rules(): array
    {
        return [
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date',
            'appId' => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:100',
            'granularity' => 'nullable|in:day,week,month',
        ];
    }
}
