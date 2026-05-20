<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DnsIpBindingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'keyword' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,released',
            'ipv4' => 'nullable|ip',
            'providerAccountId' => 'nullable|integer|min:1',
            'domainId' => 'nullable|integer|min:1',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
