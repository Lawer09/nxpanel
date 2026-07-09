<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TrafficPlatformAccountBatchUpdateTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'required|integer|min:1|distinct',
            'tags' => 'present|array|max:20',
            'tags.*' => 'nullable|string|max:50',
        ];
    }
}
