<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceNodeStats extends FormRequest
{
    public function rules(): array
    {
        return [
            'node_id' => 'required|integer',
            'days' => 'nullable|integer|min:1|max:90',
        ];
    }

    public function messages(): array
    {
        return [
            'node_id.required' => '节点ID不能为空',
            'node_id.integer' => '节点ID必须为数字',
            'days.integer' => 'days必须为数字',
            'days.min' => 'days最小为1',
            'days.max' => 'days最大为90',
        ];
    }
}
