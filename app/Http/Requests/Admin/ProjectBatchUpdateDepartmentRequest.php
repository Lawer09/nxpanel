<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectBatchUpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'required|integer|min:1|distinct',
            'department' => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'project ids are required',
            'ids.array' => 'project ids must be an array',
            'ids.min' => 'project ids are required',
            'ids.max' => 'at most 500 projects can be updated at once',
            'ids.*.distinct' => 'project ids must be unique',
            'department.max' => 'department cannot exceed 100 characters',
        ];
    }
}
