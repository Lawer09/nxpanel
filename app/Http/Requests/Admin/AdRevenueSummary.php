<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdRevenueSummary extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sourcePlatform' => 'nullable|string|max:32',
            'accountId'      => 'nullable|integer',
            'projectId'      => 'nullable|integer',
            'dateFrom'       => 'nullable|date',
            'dateTo'         => 'nullable|date',
        ];
    }
}
