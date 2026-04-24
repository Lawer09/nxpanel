<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncServerUpdate extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'serverName'   => 'sometimes|string|max:128',
            'hostIp'       => 'nullable|string|max:64',
            'secretKey'    => 'nullable|string|max:128',
            'port'         => 'nullable|integer|min:1|max:65535',
            'tags'         => 'nullable|array',
            'tags.*'       => 'string|max:64',
            'capabilities' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'serverName.max' => '服务器名称最长128个字符',
            'hostIp.max'     => 'IP地址最长64个字符',
        ];
    }
}
