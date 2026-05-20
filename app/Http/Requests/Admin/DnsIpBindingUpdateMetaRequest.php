<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DnsIpBindingUpdateMetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'tags' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:255',
        ];
    }
}
