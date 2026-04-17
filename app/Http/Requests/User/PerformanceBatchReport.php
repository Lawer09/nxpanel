<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceBatchReport extends FormRequest
{
    public function rules(): array
    {
        return [
            'reports'                          => 'required|array|min:1|max:100',
            'reports.*.node_id'                => 'required|integer',
            'reports.*.delay'                  => 'required|integer|min:0|max:60000',
            'reports.*.success_rate'           => 'required|integer|min:0|max:100',
            'reports.*.metadata'               => 'nullable|array',
            'reports.*.metadata.app_id'        => 'nullable|string|max:255',
            'reports.*.metadata.app_version'   => 'nullable|string|max:50',
            'reports.*.metadata.platform'      => 'nullable|string|max:100',
            'reports.*.metadata.brand'         => 'nullable|string|max:100',
            'reports.*.metadata.country'       => 'nullable|string|max:2',
            'reports.*.metadata.city'          => 'nullable|string|max:100',
            'reports.*.metadata.isp'           => 'nullable|string|max:255',
            'reports.*.metadata.timestamp'     => 'nullable|integer',
            'reports.*.metadata.connect_country' => 'nullable|string|max:2',
        ];
    }

    public function messages(): array
    {
        return [
            'reports.required'                    => 'reports不能为空',
            'reports.array'                       => 'reports必须为数组',
            'reports.min'                         => 'reports最少1条',
            'reports.max'                         => 'reports最多100条',
            'reports.*.node_id.required'          => 'node_id不能为空',
            'reports.*.node_id.integer'           => 'node_id必须为数字',
            'reports.*.delay.required'            => 'delay不能为空',
            'reports.*.delay.integer'             => 'delay必须为数字',
            'reports.*.delay.min'                 => 'delay最小为0',
            'reports.*.delay.max'                 => 'delay最大为60000',
            'reports.*.success_rate.required'     => 'success_rate不能为空',
            'reports.*.success_rate.integer'      => 'success_rate必须为数字',
            'reports.*.success_rate.min'          => 'success_rate最小为0',
            'reports.*.success_rate.max'          => 'success_rate最大为100',
            'reports.*.metadata.array'            => 'metadata必须为对象',
            'reports.*.metadata.country.max'      => 'country为2位国家缩写',
            'reports.*.metadata.connect_country.max' => 'connect_country为2位国家缩写',
        ];
    }
}
