<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DnsProviderAccountStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'providerCode' => 'required|string|max:32',
            'accountName' => 'required|string|max:128',
            'tags' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:255',
            'configJson' => 'nullable|array',
            'status' => 'nullable|string|in:active,disabled',
            'lastSyncedAt' => 'nullable|date',
        ];
    }
}
