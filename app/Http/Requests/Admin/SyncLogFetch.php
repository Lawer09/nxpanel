<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncLogFetch extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'server_id'    => 'nullable|string|max:64',
            'status'       => 'nullable|string|in:pending,running,success,failed',
            'scope'        => 'nullable|string|in:account_meta,apps,ad_units,revenue_daily',
            'started_from' => 'nullable|date',
            'started_to'   => 'nullable|date',
            'page'         => 'nullable|integer|min:1',
            'page_size'    => 'nullable|integer|min:1|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'          => '状态格式有误，可选值：pending, running, success, failed',
            'scope.in'           => '同步范围格式有误，可选值：account_meta, apps, ad_units, revenue_daily',
            'started_from.date'  => '开始时间格式有误',
            'started_to.date'    => '结束时间格式有误',
            'page_size.min'      => '每页条数最小为1',
            'page_size.max'      => '每页条数最大为200',
        ];
    }
}
