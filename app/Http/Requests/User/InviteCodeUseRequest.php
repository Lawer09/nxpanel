<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class InviteCodeUseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inviteCode' => 'required|string|max:64',
        ];
    }

    public function messages(): array
    {
        return [
            'inviteCode.required' => 'inviteCode is required',
            'inviteCode.string' => 'inviteCode must be a string',
            'inviteCode.max' => 'inviteCode is too long',
        ];
    }
}
