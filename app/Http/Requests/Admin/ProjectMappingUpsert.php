<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectMappingUpsert extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectId'      => 'required|integer',
            'sourcePlatform' => 'required|string|max:32',
            'accountId'      => 'required|integer|exists:ad_platform_account,id',
            'providerAppId'  => 'required|string|max:128',
            'status'         => 'required|string|in:enabled,disabled',
        ];
    }

    public function messages(): array
    {
        return [
            'projectId.required'      => '项目ID不能为空',
            'sourcePlatform.required' => '广告平台不能为空',
            'accountId.required'      => '账号ID不能为空',
            'accountId.exists'        => '账号不存在',
            'providerAppId.required'  => '应用ID不能为空',
            'status.required'         => '状态不能为空',
            'status.in'               => '状态格式有误',
        ];
    }
}
