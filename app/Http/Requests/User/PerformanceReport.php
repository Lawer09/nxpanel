<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceReport extends FormRequest
{
    public function rules(): array
    {
        return [
            'node_id' => 'required|integer',
            'delay' => 'required|integer|min:0|max:60000',
            'success_rate' => 'required|integer|min:0|max:100',
            'app_version' => 'nullable|string|max:50',
            'metadata' => 'nullable|json',
        ];
    }

    public function messages(): array
    {
        return [
            'node_id.required' => '节点ID不能为空',
            'node_id.integer' => '节点ID必须为数字',
            'delay.required' => '延迟不能为空',
            'delay.integer' => '延迟必须为数字',
            'delay.min' => '延迟最小为0',
            'delay.max' => '延迟最大为60000',
            'success_rate.required' => '成功率不能为空',
            'success_rate.integer' => '成功率必须为数字',
            'success_rate.min' => '成功率最小为0',
            'success_rate.max' => '成功率最大为100',
            'app_version.max' => '版本号最长50字符',
            'metadata.json' => 'metadata必须为合法JSON',
        ];
    }
}
