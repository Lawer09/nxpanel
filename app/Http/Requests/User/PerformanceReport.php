<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceReport extends FormRequest
{
    public function rules(): array
    {
        return [
            'node_id'                    => 'required|integer',
            'delay'                      => 'required|integer|min:0|max:60000',
            'success_rate'               => 'required|integer|min:0|max:100',
            'metadata'                   => 'nullable|array',
            'metadata.app_id'            => 'nullable|string|max:255',
            'metadata.app_version'       => 'nullable|string|max:50',
            'metadata.platform'          => 'nullable|string|max:100',
            'metadata.brand'             => 'nullable|string|max:100',
            'metadata.country'           => 'nullable|string|max:2',
            'metadata.city'              => 'nullable|string|max:100',
            'metadata.isp'              => 'nullable|string|max:255',
            'metadata.timestamp'         => 'nullable|integer',
            'metadata.connect_country'   => 'nullable|string|max:2',
        ];
    }

    public function messages(): array
    {
        return [
            'node_id.required'          => '节点ID不能为空',
            'node_id.integer'           => '节点ID必须为数字',
            'delay.required'            => '延迟不能为空',
            'delay.integer'             => '延迟必须为数字',
            'delay.min'                 => '延迟最小为0',
            'delay.max'                 => '延迟最大为60000',
            'success_rate.required'     => '成功率不能为空',
            'success_rate.integer'      => '成功率必须为数字',
            'success_rate.min'          => '成功率最小为0',
            'success_rate.max'          => '成功率最大为100',
            'metadata.array'            => 'metadata必须为对象',
            'metadata.country.max'      => 'country为2位国家缩写',
            'metadata.connect_country.max' => 'connect_country为2位国家缩写',
        ];
    }
}
