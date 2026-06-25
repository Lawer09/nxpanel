<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectBatchUpdateAdStatusRequest extends FormRequest
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
            'adStatus' => 'nullable|string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => '项目ID不能为空',
            'ids.array' => '项目ID必须为数组',
            'ids.min' => '项目ID不能为空',
            'ids.max' => '单次最多更新500个项目',
            'ids.*.distinct' => '项目ID不能重复',
            'adStatus.max' => '投放状态不能超过50个字符',
        ];
    }
}
