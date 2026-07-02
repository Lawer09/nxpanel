<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserBatchBanRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'distinct', 'exists:v2_user,id'],
            'reason' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', 'string', 'in:normal,dangerous'],
        ];
    }
}
