<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AdAccountBatchAssign extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accountIds'       => 'required|array|min:1',
            'accountIds.*'     => 'integer|exists:ad_platform_account,id',
            'assignedServerId' => 'required|string|max:64',
            'backupServerId'   => 'nullable|string|max:64',
            'isolationGroup'   => 'nullable|string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'accountIds.required'       => '账号ID列表不能为空',
            'accountIds.min'            => '至少选择一个账号',
            'accountIds.*.exists'       => '账号不存在',
            'assignedServerId.required' => '目标服务器不能为空',
        ];
    }
}
