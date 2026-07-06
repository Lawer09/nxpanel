<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectAppInfoIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectCode' => 'nullable|string|max:100',
            'projectId' => 'nullable|integer|min:1',
            'appId' => 'nullable|string|max:255',
            'enabled' => 'nullable|integer|in:0,1',
            'keyword' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'pageSize' => 'nullable|integer|min:1|max:200',
        ];
    }
}
