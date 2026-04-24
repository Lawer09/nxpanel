<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncServerSave extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'serverId'     => 'required|string|max:64',
            'serverName'   => 'required|string|max:128',
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
            'serverId.required'   => '服务器ID不能为空',
            'serverName.required' => '服务器名称不能为空',
        ];
    }
}
