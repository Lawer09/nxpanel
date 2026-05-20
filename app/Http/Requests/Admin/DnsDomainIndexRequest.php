<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DnsDomainIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => 'nullable|string|max:255',
            'providerCode' => 'nullable|string|max:32',
            'providerAccountId' => 'nullable|integer|min:1',
            'syncStatus' => 'nullable|string|in:active,disabled,missing',
            'isAvailable' => 'nullable|integer|in:0,1',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
