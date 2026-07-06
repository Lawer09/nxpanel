<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectAppInfoUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => 'required|integer|min:1',
            'projectCode' => 'nullable|string|max:100',
            'projectId' => 'nullable|integer|min:1',
            'appId' => 'nullable|string|max:255',
            'appName' => 'nullable|string|max:191',
            'platform' => 'nullable|string|max:50',
            'downloadCount' => 'nullable|integer|min:0',
            'iconUrl' => 'nullable|string|max:255',
            'chartUrl' => 'nullable|string|max:255',
            'imageUrls' => 'nullable|array',
            'imageUrls.*' => 'string|max:255',
            'storeUrl' => 'nullable|string|max:255',
            'enabled' => 'nullable|integer|in:0,1',
            'remark' => 'nullable|string|max:255',
        ];
    }
}
