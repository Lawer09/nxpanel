<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectCode' => 'required|string|max:100',
            'projectName' => 'required|string|max:100',
            'ownerName'   => 'nullable|string|max:100',
            'department'  => 'nullable|string|max:100',
            'status'      => 'nullable|string|in:active,inactive,archived',
            'remark'      => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'projectCode.required' => '项目代号不能为空',
            'projectCode.max'      => '项目代号不能超过100个字符',
            'projectName.required' => '项目名称不能为空',
            'projectName.max'      => '项目名称不能超过100个字符',
            'status.in'            => '状态格式有误，可选值：active, inactive, archived',
            'remark.max'           => '备注不能超过255个字符',
        ];
    }
}
