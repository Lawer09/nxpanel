<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectUserAppMapStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'projectId' => 'required|integer',
            'appId'     => 'required|string|max:255',
            'appLink'   => 'nullable|string|max:500',
            'enabled'   => 'nullable|integer|in:0,1',
            'remark'    => 'nullable|string|max:255',
        ];
    }
}
