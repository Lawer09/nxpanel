<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AutomationModelIndexRequest extends FormRequest
{
    /**
     * 仅管理员可访问；权限由 admin 中间件统一控制。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 查询 module 下可用 model 标识。
     */
    public function rules(): array
    {
        return [
            'module' => 'required|string|max:64',
        ];
    }
}
