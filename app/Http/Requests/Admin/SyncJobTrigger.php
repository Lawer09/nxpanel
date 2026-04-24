<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncJobTrigger extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope'            => 'required|string|in:account_meta,apps,ad_units,revenue_daily',
            'accountIds'       => 'nullable|array',
            'accountIds.*'     => 'integer|exists:ad_platform_account,id',
            'assignedServerId' => 'nullable|string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'scope.required' => '同步范围不能为空',
            'scope.in'       => '同步范围格式有误，可选值：account_meta, apps, ad_units, revenue_daily',
        ];
    }
}
