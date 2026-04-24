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
        return [
            'sourcePlatform'    => 'required|string|max:32',
            'accountName'       => 'required|string|max:128',
            'accountLabel'      => 'nullable|string|max:128',
            'authType'          => 'required|string|in:oauth,service_key',
            'credentialsJson'   => 'required|array',
            'status'            => 'required|string|in:enabled,disabled',
            'tags'              => 'nullable|array',
            'tags.*'            => 'string|max:64',
            'assignedServerId'  => 'nullable|string|max:64',
            'backupServerId'    => 'nullable|string|max:64',
            'isolationGroup'    => 'nullable|string|max:64',
            'reportingTimezone' => 'nullable|string|max:64',
            'currencyCode'      => 'nullable|string|max:8',
            'publisherId'       => 'nullable|string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'sourcePlatform.required'  => '广告平台不能为空',
            'accountName.required'     => '账号名称不能为空',
            'authType.required'        => '认证类型不能为空',
            'authType.in'              => '认证类型格式有误',
            'credentialsJson.required' => '凭据信息不能为空',
            'credentialsJson.array'    => '凭据信息格式有误',
            'status.required'          => '状态不能为空',
            'status.in'                => '状态格式有误',
        ];
    }
}
