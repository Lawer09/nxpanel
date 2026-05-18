<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'name' => 'nullable|string|max:100',
            'baseUrl' => 'nullable|string|max:255',
            'enabled' => 'nullable|integer|in:0,1',
        ];
    }
}
