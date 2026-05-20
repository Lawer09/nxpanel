<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DnsProviderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'name' => 'nullable|string|max:128',
            'tags' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:255',
            'officialWebsite' => 'nullable|url|max:255',
            'apiHost' => 'nullable|url|max:255',
            'requestTimeout' => 'nullable|integer|min:1|max:120',
            'rateLimitPerMinute' => 'nullable|integer|min:1|max:100000',
        ];
    }
}
