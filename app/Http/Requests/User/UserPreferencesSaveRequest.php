<?php

namespace App\Http\Requests\User;

use App\Services\UserPreferenceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserPreferencesSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1|max:20',
            'items.*.preferenceKey' => [
                'required',
                'string',
                'max:191',
                Rule::in(UserPreferenceService::allowedKeys()),
            ],
            'items.*.preferenceValue' => 'required|array',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => '偏好配置不能为空',
            'items.array' => '偏好配置格式不正确',
            'items.*.preferenceKey.required' => '偏好配置Key不能为空',
            'items.*.preferenceKey.in' => '偏好配置Key不允许同步',
            'items.*.preferenceValue.required' => '偏好配置值不能为空',
            'items.*.preferenceValue.array' => '偏好配置值必须是JSON对象或数组',
        ];
    }
}
