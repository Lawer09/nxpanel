<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceHistory extends FormRequest
{
    public function rules(): array
    {
        return [
            'limit' => 'nullable|integer|min:1|max:1000',
            'node_id' => 'nullable|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'limit.integer' => 'limit必须为数字',
            'limit.min' => 'limit最小为1',
            'limit.max' => 'limit最大为1000',
            'node_id.integer' => '节点ID必须为数字',
        ];
    }
}
