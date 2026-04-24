<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SyncServerUpdateStatus extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'required|string|in:online,offline,maintenance',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => '状态不能为空',
            'status.in'       => '状态格式有误，可选值：online, offline, maintenance',
        ];
    }
}
