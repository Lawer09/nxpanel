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
            'project_id'      => 'required|integer',
            'source_platform' => 'required|string|max:32',
            'account_id'      => 'required|integer|exists:ad_platform_account,id',
            'provider_app_id' => 'required|string|max:128',
            'status'          => 'required|string|in:enabled,disabled',
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required'      => '项目ID不能为空',
            'source_platform.required' => '广告平台不能为空',
            'account_id.required'      => '账号ID不能为空',
            'account_id.exists'        => '账号不存在',
            'provider_app_id.required' => '应用ID不能为空',
            'status.required'          => '状态不能为空',
            'status.in'               => '状态格式有误',
        ];
    }
}
