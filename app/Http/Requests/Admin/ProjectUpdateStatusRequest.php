<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectUpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'     => 'required|integer|min:1',
            'status' => 'required|string|in:active,inactive,archived',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => '状态不能为空',
            'status.in'       => '状态格式有误，可选值：active, inactive, archived',
        ];
    }
}
