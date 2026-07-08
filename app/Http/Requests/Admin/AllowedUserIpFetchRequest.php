<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AllowedUserIpFetchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ip' => 'nullable|ip',
            'operatorUserId' => 'nullable|integer|min:1',
            'current' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
