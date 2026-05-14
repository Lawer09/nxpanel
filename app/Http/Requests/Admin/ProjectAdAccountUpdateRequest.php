<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ProjectAdAccountUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'               => 'required|integer|min:1',
            'projectId'        => 'required|integer|min:1',
            'externalAppId'    => 'nullable|string|max:100',
            'externalAdUnitId' => 'nullable|string|max:100',
            'bindType'         => 'nullable|string|in:account,app,ad_unit',
            'enabled'          => 'nullable|integer|in:0,1',
            'remark'           => 'nullable|string|max:255',
        ];
    }
}
