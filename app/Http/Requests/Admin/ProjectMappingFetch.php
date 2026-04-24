<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectMappingFetch extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectId'      => 'nullable|string|max:64',
            'sourcePlatform' => 'nullable|string|max:32',
            'accountId'      => 'nullable|integer',
            'status'         => 'nullable|string|in:enabled,disabled',
            'page'           => 'nullable|integer|min:1',
            'pageSize'       => 'nullable|integer|min:1|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'          => '状态格式有误，可选值：enabled, disabled',
            'accountId.integer'  => '账户ID必须为数字',
            'pageSize.min'       => '每页条数最小为1',
            'pageSize.max'       => '每页条数最大为200',
        ];
    }
}
