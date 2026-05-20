<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DnsProviderAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'providerCode' => 'nullable|string|max:32',
            'accountName' => 'nullable|string|max:128',
            'tags' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:255',
            'configJson' => 'nullable|array',
            'status' => 'nullable|string|in:active,disabled',
            'lastSyncedAt' => 'nullable|date',
        ];
    }
}
