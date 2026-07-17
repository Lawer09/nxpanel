<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdSpendPlatformDailyQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dateFrom' => 'nullable|date',
            'dateTo' => 'nullable|date',
            'groupBy' => 'nullable|array',
            'groupBy.*' => 'required|string|in:date,platform_code,platform_account_id,project_code,country,platform',
            'filters' => 'nullable|array',
            'filters.platformCodes' => 'nullable|array',
            'filters.platformCodes.*' => 'string|max:50',
            'filters.platforms' => 'nullable|array',
            'filters.platforms.*' => 'string|max:50',
            'filters.accountIds' => 'nullable|array',
            'filters.accountIds.*' => 'integer',
            'filters.projectCodes' => 'nullable|array',
            'filters.projectCodes.*' => 'string|max:100',
            'filters.countries' => 'nullable|array',
            'filters.countries.*' => 'string|max:50',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
