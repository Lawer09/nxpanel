<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectFetchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword'  => 'nullable|string|max:100',
            'status'   => 'nullable|string|in:active,inactive,archived',
            'adStatus' => 'nullable|string|max:50',
            'ownerId'  => 'nullable|integer|min:1',
            'page'     => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'keyword.max'    => '搜索关键词不能超过100个字符',
            'status.in'      => '状态格式有误，可选值：active, inactive, archived',
            'adStatus.max'    => '投放状态不能超过50个字符',
            'pageSize.max'   => '每页条数最大为200',
        ];
    }
}
