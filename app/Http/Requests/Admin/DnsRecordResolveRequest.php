<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DnsRecordResolveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ipv4' => 'required|ip',
            'subdomain' => 'required|string|max:255',
            'domain' => 'required|string|max:255',
            'unique' => 'nullable|boolean',
        ];
    }
}
