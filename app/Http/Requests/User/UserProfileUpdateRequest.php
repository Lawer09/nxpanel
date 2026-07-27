<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserProfileUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = (int) ($this->user()?->id ?? 0);

        return [
            'email' => [
                'required',
                'email:strict',
                'max:64',
                Rule::unique('v2_user', 'email')->ignore($userId),
            ],
            'nickname' => 'nullable|string|max:100',
            'password' => 'nullable|string|min:8|not_regex:/\s/',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'email.unique' => '邮箱已被使用',
            'nickname.max' => '昵称不能超过100个字符',
            'password.min' => '密码至少8位',
            'password.not_regex' => '密码不能包含空格、换行等空白字符',
        ];
    }
}
