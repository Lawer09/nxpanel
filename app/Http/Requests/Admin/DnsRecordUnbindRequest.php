<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DnsRecordUnbindRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ipv4' => 'required|ip',
            'fqdn' => 'required|string|max:512',
        ];
    }
}
