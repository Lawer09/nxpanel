<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdAccountFetch extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sourcePlatform'   => 'nullable|string|max:32',
            'status'           => 'nullable|string|in:enabled,disabled',
            'assignedServerId' => 'nullable|string|max:64',
            'keyword'          => 'nullable|string|max:128',
            'page'             => 'nullable|integer|min:1',
            'pageSize'         => 'nullable|integer|min:1|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'     => '状态格式有误，可选值：enabled, disabled',
            'pageSize.min'  => '每页条数最小为1',
            'pageSize.max'  => '每页条数最大为200',
        ];
    }
}
