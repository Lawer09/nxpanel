<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectResourceIdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'        => 'required|integer|min:1',
            'projectId' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'id.required'        => '关联记录 ID 不能为空',
            'id.integer'         => '关联记录 ID 必须为整数',
            'id.min'             => '关联记录 ID 必须大于 0',
            'projectId.required' => '项目 ID 不能为空',
            'projectId.integer'  => '项目 ID 必须为整数',
            'projectId.min'      => '项目 ID 必须大于 0',
        ];
    }
}
