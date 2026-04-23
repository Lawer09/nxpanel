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
            'account_ids'        => 'required|array|min:1',
            'account_ids.*'      => 'integer|exists:ad_platform_account,id',
            'assigned_server_id' => 'required|string|max:64',
            'backup_server_id'   => 'nullable|string|max:64',
            'isolation_group'    => 'nullable|string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'account_ids.required'        => '账号ID列表不能为空',
            'account_ids.min'             => '至少选择一个账号',
            'account_ids.*.exists'        => '账号不存在',
            'assigned_server_id.required' => '目标服务器不能为空',
        ];
    }
}
