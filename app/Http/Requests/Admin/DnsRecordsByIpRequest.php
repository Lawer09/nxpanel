<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DnsRecordsByIpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ipv4' => 'required|ip',
            'status' => 'nullable|string|in:active,released',
        ];
    }
}
