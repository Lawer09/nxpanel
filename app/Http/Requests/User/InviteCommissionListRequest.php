<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class InviteCommissionListRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }

    public function messages(): array
    {
        return [
            'page.integer' => 'page must be an integer',
            'page.min' => 'page must be at least 1',
            'pageSize.integer' => 'pageSize must be an integer',
            'pageSize.min' => 'pageSize must be at least 1',
            'pageSize.max' => 'pageSize must be at most 200',
        ];
    }
}
