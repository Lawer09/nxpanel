<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class AllowedUserIpSaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ips' => 'required|array|min:1|max:500',
            'ips.*' => 'required|ip',
            'reason' => 'nullable|string|max:500',
        ];
    }
}
