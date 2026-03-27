<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class IpPoolSave extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $rules = [
            'hostname' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'region' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:2',
            'loc' => 'nullable|string|max:50',
            'org' => 'nullable|string|max:255',
            'postal' => 'nullable|string|max:20',
            'timezone' => 'nullable|string|max:50',
            'score' => 'nullable|integer|min:0|max:100',
            'status' => 'nullable|in:active,cooldown',
            'risk_level' => 'nullable|integer|min:0|max:100',
        ];

        // 新增时IP必填
        if (!$this->input('id')) {
            $rules['ip'] = 'required|ip|unique:v2_ip_pool,ip';
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'ip.required' => 'IP地址不能为空',
            'ip.ip' => 'IP地址格式错误',
            'ip.unique' => '该IP已存在',
            'score.min' => '评分不能小于0',
            'score.max' => '评分不能大于100',
        ];
    }
}