<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectAggregateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'projectId' => 'nullable|integer|min:1|exists:project_projects,id',
        ];
    }

    public function messages(): array
    {
        return [
            'startDate.required' => '开始日期不能为空',
            'endDate.required' => '结束日期不能为空',
            'endDate.after_or_equal' => '结束日期必须大于或等于开始日期',
            'projectId.exists' => '项目不存在',
        ];
    }
}
