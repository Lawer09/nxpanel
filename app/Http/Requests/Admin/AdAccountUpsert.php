<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdAccountUpsert extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // 编辑时 credentials_json 不会返回前端，允许不传（保留原值）
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'source_platform'    => 'required|string|max:32',
            'account_name'       => 'required|string|max:128',
            'account_label'      => 'nullable|string|max:128',
            'auth_type'          => 'required|string|in:oauth,service_key',
            'credentials_json'   => ($isUpdate ? 'nullable' : 'required') . '|array',
            'status'             => 'required|string|in:enabled,disabled',
            'tags'               => 'nullable|array',
            'tags.*'             => 'string|max:64',
            'assigned_server_id' => 'nullable|string|max:64',
            'backup_server_id'   => 'nullable|string|max:64',
            'isolation_group'    => 'nullable|string|max:64',
            'reporting_timezone' => 'nullable|string|max:64',
            'currency_code'      => 'nullable|string|max:8',
            'publisher_id'       => 'nullable|string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'source_platform.required'  => '广告平台不能为空',
            'account_name.required'     => '账号名称不能为空',
            'auth_type.required'        => '认证类型不能为空',
            'auth_type.in'              => '认证类型格式有误',
            'credentials_json.required' => '凭据信息不能为空',
            'credentials_json.array'    => '凭据信息格式有误',
            'status.required'           => '状态不能为空',
            'status.in'                 => '状态格式有误',
        ];
    }
}
