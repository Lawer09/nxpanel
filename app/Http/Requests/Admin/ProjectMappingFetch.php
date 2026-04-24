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
            'project_id'      => 'nullable|string|max:64',
            'source_platform' => 'nullable|string|max:32',
            'account_id'      => 'nullable|integer',
            'status'          => 'nullable|string|in:enabled,disabled',
            'page'            => 'nullable|integer|min:1',
            'page_size'       => 'nullable|integer|min:1|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'        => '状态格式有误，可选值：enabled, disabled',
            'account_id.integer' => '账户ID必须为数字',
            'page_size.min'    => '每页条数最小为1',
            'page_size.max'    => '每页条数最大为200',
        ];
    }
}
