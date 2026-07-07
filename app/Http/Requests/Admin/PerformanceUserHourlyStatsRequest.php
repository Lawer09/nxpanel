<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceUserHourlyStatsRequest extends FormRequest
{
    /**
     * Allow admins to query recent hourly user stats.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validate hourly user stats filters.
     */
    public function rules(): array
    {
        return [
            'appId' => 'nullable|string|max:255',
            'platform' => 'nullable|string|max:100',
            'appVersion' => 'nullable|string|max:50',
            'clientCountry' => 'nullable|string|max:2',
        ];
    }
}
