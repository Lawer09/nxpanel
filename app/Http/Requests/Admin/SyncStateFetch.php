<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncStateFetch extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scope'      => 'nullable|string|in:account_meta,apps,ad_units,revenue_daily',
            'account_id' => 'nullable|integer',
            'page'       => 'nullable|integer|min:1',
            'page_size'  => 'nullable|integer|min:1|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'scope.in'         => '同步范围格式有误，可选值：account_meta, apps, ad_units, revenue_daily',
            'account_id.integer' => '账户ID必须为数字',
            'page_size.min'    => '每页条数最小为1',
            'page_size.max'    => '每页条数最大为200',
        ];
    }
}
