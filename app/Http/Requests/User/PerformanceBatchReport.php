<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class PerformanceBatchReport extends FormRequest
{
    public function rules(): array
    {
        return [
            'reports'                    => 'nullable|array|min:0|max:100',
            'reports.*.node_id'          => 'nullable|integer',
            'reports.*.node_ip'          => 'nullable|string|max:255',
            'reports.*.vpn_node_ip'      => 'nullable|string|max:255',
            'reports.*.delay'            => 'required|integer',
            'reports.*.success_rate'     => 'required|integer|min:0|max:100',
            'reports.*.status'           => 'nullable|in:success,failed,timeout,cancelled',
            'reports.*.probe_stage'      => 'nullable|in:node_connect,tunnel_establish,post_connect_probe',
            'reports.*.error_code'       => 'nullable|string|max:64',
            'reports.*.vpn_user_time'    => 'nullable',
            'reports.*.vpn_user_traffic' => 'nullable',
            'reports.*.arise_timestamp'  => 'nullable|integer',
            'metadata'                   => 'required|array',
            'metadata.app_id'            => 'required|string|max:255',
            'metadata.app_version'       => 'nullable|string|max:50',
            'metadata.platform'          => 'nullable|string|max:100',
            'metadata.brand'             => 'nullable|string|max:100',
            'metadata.country'           => 'nullable|string|max:2',
            'metadata.city'              => 'nullable|string|max:100',
            'metadata.isp'               => 'nullable|string|max:255',
            'metadata.timestamp'         => 'required|integer',
            'metadata.connect_country'   => 'nullable|string|max:2',
        ];
    }

    public function messages(): array
    {
        return [
            'reports.max'                         => 'reports最多100条',
            'reports.*.node_id.integer'           => 'node_id必须为数字',
            'reports.*.node_ip.max'               => 'node_ip长度不能超过255',
            'reports.*.vpn_node_ip.max'           => 'vpn_node_ip长度不能超过255',
            'reports.*.delay.required'            => 'delay不能为空',
            'reports.*.delay.integer'             => 'delay必须为数字',
            'reports.*.success_rate.required'     => 'success_rate不能为空',
            'reports.*.success_rate.integer'      => 'success_rate必须为数字',
            'reports.*.success_rate.min'          => 'success_rate最小为0',
            'reports.*.success_rate.max'          => 'success_rate最大为100',
            'reports.*.status.in'                 => 'status取值不合法',
            'reports.*.probe_stage.in'            => 'probe_stage取值不合法',
            'reports.*.error_code.max'            => 'error_code长度不能超过64',
            'reports.*.arise_timestamp.integer'   => 'arise_timestamp必须为数字',
            'metadata.array'                      => 'metadata必须为对象',
            'metadata.country.max'                => 'country为2位国家缩写',
            'metadata.connect_country.max'        => 'connect_country为2位国家缩写',
        ];
    }
}
