<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectAppInfoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appId' => 'required|string|max:255',
            'appName' => 'nullable|string|max:191',
            'platform' => 'nullable|string|max:50',
            'downloadCount' => 'nullable|integer|min:0',
            'downloadData' => 'nullable|array',
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
