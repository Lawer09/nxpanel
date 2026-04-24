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
            'server_id'    => 'required|string|max:64',
            'server_name'  => 'required|string|max:128',
            'host_ip'      => 'nullable|string|max:64',
            'secret_key'   => 'nullable|string|max:128',
            'port'         => 'nullable|integer|min:1|max:65535',
            'tags'         => 'nullable|array',
            'tags.*'       => 'string|max:64',
            'capabilities' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'server_id.required'   => '服务器ID不能为空',
            'server_name.required' => '服务器名称不能为空',
        ];
    }
}
